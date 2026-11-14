<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDrafts;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentInput;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssue;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssues;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentOutcome;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentRows;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentService;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentTracking;

/**
 * Блок відправлення на сторінці замовлення надсилає сюди (`controller=Sviat.Ukrposhta.ShipmentAdmin`).
 * Доступ потребує права `orders`; кожна дія — POST із CSRF-токеном. Ярлик іде через цей контролер:
 * токен контрагента в посиланні бази форм, тож браузер не повинен його бачити.
 */
class ShipmentAdmin extends IndexAdmin
{
    private const string KEY = 'sviat__ukrposhta__';
    /** Відмови, де value — id іншого замовлення магазину: менеджер відкриває його, а не шукає. */
    private const array ORDER_LINKS = ['sviat__ukrposhta__ship_err_barcode_elsewhere'];

    public function fetch(
        ShipmentService $service,
        ShipmentDrafts $drafts,
        ShipmentTracking $tracking,
        Labels $labels,
        ShipmentRows $rows,
        BackendTranslations $translations,
        OrderDeliveryLines $lines,
    ) {
        if (!$this->request->method('post')) {
            $this->answer(405, ['ok' => false]);

            return;
        }
        // Сток 4.5.3 перевіряє session_id лише після виконання контролера.
        if (!$this->request->checkSession()) {
            $this->answer(403, ['ok' => false]);

            return;
        }
        $orderId = (int)$this->request->post('order_id', 'integer');
        $order = $orderId > 0 ? $this->entityFactory->get(OrdersEntity::class)->get($orderId) : null;
        if (!$order) {
            $this->answer(404, ['ok' => false]);

            return;
        }

        $action = (string)$this->request->post('action');
        if ($action === 'label') {
            $this->label($labels, $rows, $translations, $orderId);

            return;
        }
        // Нове відправлення — лише для замовлення, яке досі доставляє модуль; наявне можна видалити чи відв'язати й надалі.
        if (in_array($action, ['create', 'attach'], true) && $lines->ownDelivery($order) === null) {
            $this->answer(200, self::view(ShipmentOutcome::refused(self::KEY . 'ship_err_not_own'), $translations));

            return;
        }
        $outcome = match ($action) {
            'create' => $this->create($service, $drafts, $orderId),
            'lookup' => $service->lookup($orderId, self::draftPayer($drafts, $orderId)),
            'attach' => $service->attach($orderId, (string)$this->request->post('barcode')),
            'remove' => $service->remove($orderId),
            'unlink' => $service->unlink($orderId),
            'refresh' => $tracking->refresh($orderId),
            default => null,
        };
        if ($outcome === null) {
            $this->answer(400, ['ok' => false]);

            return;
        }

        $this->answer(200, self::view($outcome, $translations));
    }

    private function create(ShipmentService $service, ShipmentDrafts $drafts, int $orderId): ShipmentOutcome
    {
        $draft = $drafts->forOrder($orderId);
        if (is_string($draft)) {
            return ShipmentOutcome::refused($draft);
        }
        $edited = $draft->withInput(ShipmentInput::fromPost($_POST));
        if (!$edited instanceof ShipmentDraft) {
            return ShipmentOutcome::failed(array_map(
                static fn(string $field, string $key): array => ['field' => $field] + ShipmentIssues::explain($key),
                array_keys($edited),
                $edited,
            ));
        }

        return $service->create($orderId, $edited);
    }

    /** Платник, з яким пішов би новий запит; відповідь Укрпошти без платника інакше не сховала б ціну на ярлику. */
    private static function draftPayer(ShipmentDrafts $drafts, int $orderId): ?bool
    {
        $draft = $drafts->forOrder($orderId);

        return $draft instanceof ShipmentDraft ? $draft->paidByRecipient : null;
    }

    private function label(Labels $labels, ShipmentRows $rows, BackendTranslations $translations, int $orderId): void
    {
        $size = $this->request->post('size');
        $size = in_array($size, [Labels::SIZE_10X10, Labels::SIZE_A4], true) ? $size : $labels->defaultSize();
        try {
            $pdf = $labels->one($orderId, $size);
        } catch (ShipmentIssue $e) {
            $this->answer(200, self::view(ShipmentOutcome::refused($e->getMessage()), $translations));

            return;
        } catch (UkrposhtaApiException $e) {
            $this->answer(200, self::view(ShipmentOutcome::failed([ShipmentIssues::explain($e)]), $translations));

            return;
        }
        $barcode = preg_replace('/[^A-Z0-9]/', '', (string)($rows->forOrder($orderId)->barcode ?? ''));

        $this->response->setStatusCode(200);
        $this->response->addHeader('Content-Type: application/pdf');
        $this->response->addHeader('Content-Disposition: attachment; filename="ukrposhta-' . $barcode . '.pdf"');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        $this->response->setContent($pdf, RESPONSE_TEXT);
    }

    /** @return array{ok: bool, barcode: ?string, issues: list<array<string, ?string>>, notices: list<string>} */
    private static function view(ShipmentOutcome $outcome, BackendTranslations $translations): array
    {
        $text = static fn(?string $key): ?string => $key === null ? null : (string)$translations->getTranslation($key);

        return [
            'ok' => $outcome->ok,
            'barcode' => $outcome->barcode,
            'issues' => array_map(static fn(array $issue): array => [
                'field' => $issue['field'] ?? null,
                'text' => trim($text($issue['text']) . ' ' . (string)$outcome->value),
                'advice' => $text($issue['advice'] ?? null),
                'details' => $issue['details'] ?? null,
                'orderUrl' => in_array($issue['text'], self::ORDER_LINKS, true) && ctype_digit((string)$outcome->value)
                    ? 'index.php?controller=OrderAdmin&id=' . (int)$outcome->value : null,
            ], $outcome->issues),
            'notices' => array_map(static fn(string $key): string => (string)$text($key), $outcome->notices),
        ];
    }

    private function answer(int $status, array $body): void
    {
        $this->response->setStatusCode($status);
        $this->response->setContent(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), RESPONSE_JSON);
    }
}
