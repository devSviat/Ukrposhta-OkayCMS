<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentHistory;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentRows;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use Okay\Modules\Sviat\Ukrposhta\Shipments\TrackingEvent;

/** Повторює унікальні order_id і barcode таблиці: дубль не записується, як і в базі. */
final class InMemoryShipmentRows implements ShipmentRows
{
    /** @var array<int, object> за id замовлення */
    public array $rows = [];
    public int $now = 1_900_000_000;
    /** База відхилила запис після того, як Укрпошта створила відправлення. */
    public bool $failComplete = false;
    /** Що встигло змінитися в базі між вибіркою тіку й його записом (наприклад, кнопка «Оновити»). */
    public ?\Closure $afterDue = null;

    public function forOrder(int $orderId): ?object
    {
        return $this->rows[$orderId] ?? null;
    }

    public function forOrders(array $orderIds): array
    {
        return array_intersect_key($this->rows, array_flip($orderIds));
    }

    public function orderOfBarcode(string $barcode): ?int
    {
        foreach ($this->rows as $orderId => $row) {
            if ($row->barcode === $barcode) {
                return $orderId;
            }
        }

        return null;
    }

    public function reserve(int $orderId): bool
    {
        if (isset($this->rows[$orderId])) {
            return false;
        }
        $at = date('Y-m-d H:i:s', $this->now);
        $this->rows[$orderId] = (object)[
            'order_id' => $orderId, 'uuid' => null, 'sender_uuid' => null, 'barcode' => null, 'state' => PendingShipment::PENDING, 'status' => null,
            'event_code' => null, 'delivery_price' => null, 'cod_amount' => null, 'cod_fee' => null,
            'created_at' => $at, 'reserved_at' => $at,
        ];

        return true;
    }

    public function complete(int $orderId, array $shipment): bool
    {
        $owner = $this->orderOfBarcode($shipment['barcode']);
        if ($this->failComplete || !PendingShipment::is($this->rows[$orderId] ?? null) || ($owner !== null && $owner !== $orderId)) {
            return false;
        }
        $this->rows[$orderId] = (object)(['state' => PendingShipment::CREATED, 'reserved_at' => null, 'status_group' => self::initialGroup($shipment)] + $shipment + (array)$this->rows[$orderId]);

        return true;
    }

    public function add(int $orderId, array $shipment): bool
    {
        if (isset($this->rows[$orderId]) || $this->orderOfBarcode($shipment['barcode']) !== null) {
            return false;
        }
        $this->rows[$orderId] = (object)(['order_id' => $orderId, 'state' => PendingShipment::CREATED, 'event_code' => null, 'created_at' => date('Y-m-d H:i:s', $this->now), 'reserved_at' => null, 'status_group' => self::initialGroup($shipment)] + $shipment);

        return true;
    }

    public function rememberSender(int $orderId, string $senderUuid): void
    {
        if (PendingShipment::is($this->rows[$orderId] ?? null)) {
            $this->rows[$orderId]->sender_uuid = $senderUuid;
        }
    }

    public function release(int $orderId): void
    {
        if (PendingShipment::is($this->rows[$orderId] ?? null)) {
            unset($this->rows[$orderId]);
        }
    }

    public function markUncertain(int $orderId): void
    {
        if (PendingShipment::is($this->rows[$orderId] ?? null)) {
            $this->rows[$orderId]->state = PendingShipment::UNCERTAIN;
        }
    }

    public function remove(int $orderId): void
    {
        unset($this->rows[$orderId]);
    }

    public function dueForTracking(int $limit, string $createdSince, string $checkedBefore): array
    {
        $due = array_filter($this->rows, static fn(object $row): bool => !PendingShipment::is($row)
            && ($row->barcode ?? null) !== null
            && ($row->created_at ?? '') >= $createdSince
            && !in_array($row->status_group ?? null, ShipmentStatus::FINAL, true)
            && (($row->checked_at ?? null) === null || $row->checked_at < $checkedBefore));
        usort($due, static fn(object $a, object $b): int => [$a->checked_at ?? '', $a->order_id] <=> [$b->checked_at ?? '', $b->order_id]);
        // Як і база, віддає знімки рядків: пізніші зміни рядка їх не торкаються.
        $snapshots = array_map(static fn(object $row): object => clone $row, array_slice(array_values($due), 0, $limit));
        if ($this->afterDue !== null) {
            ($this->afterDue)();
        }

        return $snapshots;
    }

    public function dueForLifecycle(int $limit, string $createdSince, string $checkedBefore): array
    {
        $due = array_filter($this->rows, static fn(object $row): bool => !PendingShipment::is($row)
            && ($row->uuid ?? null) !== null
            && ($row->event_code ?? null) === null
            && in_array($row->status ?? null, [null, 'CREATED'], true)
            && ($row->created_at ?? '') >= $createdSince
            && (($row->lifecycle_checked_at ?? null) === null || $row->lifecycle_checked_at < $checkedBefore));
        usort($due, static fn(object $a, object $b): int => [$a->lifecycle_checked_at ?? '', $a->order_id] <=> [$b->lifecycle_checked_at ?? '', $b->order_id]);

        return array_slice(array_values($due), 0, $limit);
    }

    public function saveEvent(int $orderId, array $event, string $checkedAt): void
    {
        $this->update($orderId, $event + ['checked_at' => $checkedAt]);
    }

    public function saveTickEvent(int $orderId, array $event, TrackingEvent $latest, string $checkedAt): void
    {
        $history = ShipmentHistory::decode($this->rows[$orderId]->history ?? null);
        $this->update($orderId, $event + ['checked_at' => $checkedAt, 'history' => ShipmentHistory::encode(ShipmentHistory::append($history, $latest))]);
    }

    public function markChecked(int $orderId, string $checkedAt): void
    {
        $this->update($orderId, ['checked_at' => $checkedAt]);
    }

    public function saveLifecycle(int $orderId, string $status, string $checkedAt): void
    {
        $this->update($orderId, ['status' => $status, 'lifecycle_checked_at' => $checkedAt]);
    }

    /** @param array<string, mixed> $values */
    private function update(int $orderId, array $values): void
    {
        $row = $this->rows[$orderId] ?? null;
        if ($row !== null && !PendingShipment::is($row)) {
            $values += ['status_group' => ShipmentStatus::afterUpdate($row, $values)];
            foreach ($values as $field => $value) {
                $row->{$field} = $value;
            }
        }
    }

    /** @param array<string, mixed> $shipment */
    private static function initialGroup(array $shipment): string
    {
        return ShipmentStatus::group(null, $shipment['status'] ?? null, null) ?? 'created';
    }
}
