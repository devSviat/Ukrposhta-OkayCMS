<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/**
 * Остання відмова авторизації кожного API, доки її не зніме успішна відповідь, новий ключ чи успішна перевірка ключів.
 * Відкликаний ключ сам себе не виявляє: позначку показують сторінки налаштувань і замовлення.
 * Кожне API має власний рядок стану: кошик, планувальник і адмінка пишуть одночасно, і спільний рядок губив би чужі зміни.
 */
final class AuthFlags
{
    /** Запити eCom з токеном контрагента: відправлення, ярлики, клієнти. */
    public const string ECOM = 'ecom';
    public const string TRACKING = 'tracking';
    /** Калькулятор ціни в кошику: лише bearer eCom, без токена. */
    public const string QUOTE = 'quote';

    private const array STATE = [
        self::ECOM => 'ecom_auth_failed',
        self::TRACKING => 'tracking_auth_failed',
        self::QUOTE => 'quote_auth_failed',
    ];
    private const array REASONS = [ApiErrorKind::BadBearer->value, ApiErrorKind::BadToken->value];

    public function __construct(private readonly StateStorage $state)
    {
    }

    /** @return bool true, коли це відмова авторизації (позначку виставлено) */
    public function record(string $api, UkrposhtaApiException $e): bool
    {
        if (!in_array($e->kind->value, self::REASONS, true)) {
            return false;
        }
        // Трекінг і калькулятор не мають токена контрагента: їхній 401 стосується bearer, і банер має назвати саме його.
        $this->set($api, $api === self::ECOM ? $e->kind->value : ApiErrorKind::BadBearer->value);

        return true;
    }

    public function clear(string ...$apis): void
    {
        foreach ($apis as $api) {
            $this->set($api, null);
        }
    }

    public function reason(string $api): ?string
    {
        $reason = $this->state->get(self::STATE[$api]);

        return in_array($reason, self::REASONS, true) ? $reason : null;
    }

    /** @return list<array{api: string, reason: string}> reason: bad_bearer | bad_token */
    public function items(): array
    {
        $items = [];
        foreach ([self::ECOM, self::TRACKING, self::QUOTE] as $api) {
            $reason = $this->reason($api);
            if ($reason !== null) {
                $items[] = ['api' => $api, 'reason' => $reason];
            }
        }

        return $items;
    }

    /** Пише лише зміну: успішний запит не повинен щоразу оновлювати рядок стану. */
    private function set(string $api, ?string $reason): void
    {
        $name = self::STATE[$api];
        if ($this->state->get($name) !== $reason) {
            $this->state->set($name, $reason);
        }
    }
}
