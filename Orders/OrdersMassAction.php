<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Shipments\LabelFile;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassReport;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassShipments;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssue;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssues;
use Psr\Log\LoggerInterface;

/**
 * Дії списку замовлень «створити відправлення» і «надрукувати ярлики». Ядро не передає дії списку
 * жодному модулю, тож надіслану дію беруть із хука фільтра самого списку; звіт чекає в сесії
 * на сторінку, на яку повертають браузер.
 */
final class OrdersMassAction
{
    public const string CREATE = 'sviat_up_create_shipments';
    public const string PRINT = 'sviat_up_print_labels';
    private const string SESSION_KEY = 'sviat_up_mass';
    private const string KEY = 'sviat__ukrposhta__';

    public function __construct(
        private readonly MassShipments $mass,
        private readonly Labels $labels,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $sessionOk CSRF-токен адміна збігся: сток перевіряє його лише після контролера
     * @return bool|LabelFile false — дія не цього модуля; true — сторінку перезавантажити редиректом; файл — віддати браузеру
     */
    public function handle(array $post, bool $sessionOk, array &$session): bool|LabelFile
    {
        $action = $post['action'] ?? null;
        if (!in_array($action, [self::CREATE, self::PRINT], true) || !is_array($post['check'] ?? null) || !$sessionOk) {
            return false;
        }
        if ($action === self::CREATE) {
            // Закрита вкладка не має обривати запит між резервацією і відповіддю Укрпошти.
            ignore_user_abort(true);
            // Сесія лишається заблокованою до MassShipments::BUDGET_SECONDS: інші вкладки адмінки цього менеджера
            // чекають. Звіт пишеться в ту саму сесію, тож закривати її раніше означало б відкривати знову.
            $session[self::SESSION_KEY] = ['report' => $this->mass->run($post['check'])->toArray()];

            return true;
        }

        try {
            return LabelFile::fromPdfs($this->labels->many(MassShipments::ids($post['check']), $this->labels->defaultSize()), date('Ymd-His'));
        } catch (ShipmentIssue $e) {
            $session[self::SESSION_KEY] = ['error' => ['key' => $e->getMessage(), 'details' => null]];
        } catch (UkrposhtaApiException $e) {
            $issue = ShipmentIssues::explain($e);
            $session[self::SESSION_KEY] = ['error' => ['key' => $issue['text'], 'details' => $issue['details']]];
        } catch (\Throwable $e) {
            // Замість 500 менеджер повертається до списку з повідомленням; причина лише в лозі.
            $this->logger->error('Ukrposhta label print failed: ' . UkrposhtaApiException::describe($e));
            $session[self::SESSION_KEY] = ['error' => ['key' => self::KEY . 'label_err_generic', 'details' => null]];
        }

        return true;
    }

    /**
     * handle() для поточного запиту. Сток 4.5.3 checkSession() очищає $_POST за хибного токена,
     * тому POST читається до перевірки.
     *
     * @param \Closure(): bool $checkSession
     */
    public function handleRequest(\Closure $checkSession, array &$session): bool|LabelFile
    {
        $post = $_POST;

        return $this->handle($post, (bool)$checkSession(), $session);
    }

    /** @return ?array{report: ?MassReport, error: ?array{key: string, details: ?string}} */
    public static function take(array &$session): ?array
    {
        if (!array_key_exists(self::SESSION_KEY, $session)) {
            return null;
        }
        $data = $session[self::SESSION_KEY];
        unset($session[self::SESSION_KEY]);
        $report = isset($data['report']) ? MassReport::fromArray($data['report']) : null;
        $error = $data['error'] ?? null;
        $error = is_array($error) && is_string($error['key'] ?? null) && str_starts_with($error['key'], self::KEY)
            && (($error['details'] ?? null) === null || is_string($error['details']))
            ? ['key' => $error['key'], 'details' => $error['details'] ?? null]
            : null;

        return $report === null && $error === null ? null : ['report' => $report, 'error' => $error];
    }

    /**
     * @param array{report: ?MassReport, error: ?array{key: string, details: ?string}} $taken
     * @param \Closure(string): string $text повний мовний ключ → текст
     * @return array{title: ?string, rows: list<array{orderId: int, kind: string, text: string}>, untouched: ?string, error: ?string}
     */
    public static function view(array $taken, \Closure $text): array
    {
        $report = $taken['report'];
        $error = $taken['error'];
        $rows = $report === null ? [] : array_map(static function (array $row) use ($text): array {
            $value = (string)($row['value'] ?? '');
            $line = match ($row['kind']) {
                'created' => sprintf($text(self::KEY . 'mass_created'), $value),
                'skipped' => $text(self::KEY . 'mass_skipped') . ': ' . $text((string)$row['key']) . ($value !== '' ? ' ' . $value : ''),
                default => $text(self::KEY . 'mass_failed') . ': ' . $text((string)$row['key']) . ($value !== '' ? ' (' . $value . ')' : ''),
            };

            return ['orderId' => $row['orderId'], 'kind' => $row['kind'], 'text' => $line];
        }, $report->rows);

        return [
            'title' => $report === null ? null : sprintf($text(self::KEY . 'mass_title'), $report->created(), $report->processed()),
            'rows' => $rows,
            'untouched' => $report !== null && $report->untouched > 0 ? sprintf($text(self::KEY . 'mass_untouched'), MassShipments::LIMIT, $report->untouched) : null,
            'error' => $error === null ? null : trim($text($error['key']) . ' ' . (string)$error['details']),
        ];
    }
}
