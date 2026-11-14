<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Psr\Log\LoggerInterface;

/**
 * Створює відправлення для вибраних у списку замовлень, одне за одним у цьому запиті, кожне
 * з першою чернеткою замовлення, як форма без змін менеджера.
 */
final class MassShipments
{
    /** Кожне замовлення — до п'яти викликів Укрпошти; сторінка чекає на всі. */
    public const int LIMIT = 20;
    /**
     * Після цього нове замовлення не починається. nginx за замовчуванням дає PHP 60 с, а останньому
     * розпочатому замовленню ще потрібні власні кілька секунд; решта лишається на наступний запуск.
     */
    public const float BUDGET_SECONDS = 45.0;
    private const string KEY = 'sviat__ukrposhta__';

    private readonly \Closure $clock;

    /** @param ?\Closure(): float $clock секунди */
    public function __construct(
        private readonly ShipmentService $service,
        private readonly ShipmentRows $rows,
        private readonly MassOrders $orders,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** @param array<mixed> $rawIds надісланий `check[]` */
    public function run(array $rawIds): MassReport
    {
        $ids = self::ids($rawIds);
        $batch = array_slice($ids, 0, self::LIMIT);
        $untouched = count($ids) - count($batch);
        $start = ($this->clock)();
        $rows = [];
        foreach ($batch as $index => $orderId) {
            if (($this->clock)() - $start > self::BUDGET_SECONDS) {
                $untouched += count($batch) - $index;
                break;
            }
            try {
                $rows[] = ['orderId' => $orderId] + $this->one($orderId);
            } catch (\Throwable $e) {
                // Одне зламане замовлення не має позбавити звіту решту; причина лишається лише в лозі.
                $this->logger->error("Ukrposhta mass shipment for order {$orderId} failed: " . UkrposhtaApiException::describe($e));
                $rows[] = ['orderId' => $orderId, 'kind' => 'failed', 'key' => self::KEY . 'mass_failed_generic', 'value' => null];
            }
        }

        return new MassReport($rows, $untouched);
    }

    /**
     * @param array<mixed> $raw надісланий `check[]`
     * @return list<int> додатні, унікальні, у надісланому порядку
     */
    public static function ids(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if ((is_string($value) || is_int($value)) && preg_match('/^\d{1,10}$/', (string)$value) && (int)$value > 0) {
                $ids[(int)$value] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return array{kind: 'created'|'skipped'|'failed', key: ?string, value: ?string} */
    private function one(int $orderId): array
    {
        $skip = static fn(string $key, ?string $value = null): array => ['kind' => 'skipped', 'key' => $key, 'value' => $value];
        if (!$this->orders->isOurs($orderId)) {
            return $skip(self::KEY . 'ship_err_not_own');
        }
        $existing = $this->rows->forOrder($orderId);
        if ($existing !== null) {
            // Резервацію чи невідоме створення розбирають на сторінці замовлення: пошук або прив'язка, не повтор.
            return PendingShipment::is($existing)
                ? $skip(self::KEY . 'mass_unsettled')
                : $skip(self::KEY . 'mass_has_shipment', (string)$existing->barcode);
        }
        $draft = $this->orders->draft($orderId);
        if (is_string($draft)) {
            return $skip($draft);
        }
        $errors = $draft->errors();
        if ($errors !== []) {
            return $skip((string)reset($errors));
        }

        $outcome = $this->service->create($orderId, $draft);
        if ($outcome->ok) {
            return ['kind' => 'created', 'key' => null, 'value' => $outcome->barcode];
        }
        $first = $outcome->issues[0] ?? ['text' => self::KEY . 'mass_failed_generic', 'details' => null];

        return ['kind' => 'failed', 'key' => $first['text'], 'value' => $first['details'] ?? $outcome->value];
    }
}
