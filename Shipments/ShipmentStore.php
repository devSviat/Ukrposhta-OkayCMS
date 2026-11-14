<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\ShipmentEntity;

final class ShipmentStore implements ShipmentRows
{
    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    public function forOrder(int $orderId): ?object
    {
        $row = $this->entity()->findOne(['order_id' => $orderId]);

        return is_object($row) ? $row : null;
    }

    public function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds), static fn(int $id): bool => $id > 0)));
        if ($orderIds === []) {
            return [];
        }
        $rows = [];
        foreach ($this->entity()->find(['order_id' => $orderIds, 'limit' => count($orderIds)]) as $row) {
            $rows[(int)$row->order_id] = $row;
        }

        return $rows;
    }

    public function orderOfBarcode(string $barcode): ?int
    {
        $row = $this->entity()->findOne(['barcode' => $barcode]);

        return is_object($row) ? (int)$row->order_id : null;
    }

    public function reserve(int $orderId): bool
    {
        $now = date('Y-m-d H:i:s');

        // Ядро ковтає помилку дубліката ключа й повертає false: так другий запит дізнається, що замовлення зайняте.
        return (bool)$this->entity()->add([
            'order_id' => $orderId,
            'state' => PendingShipment::PENDING,
            'created_at' => $now,
            'reserved_at' => $now,
        ]);
    }

    public function complete(int $orderId, array $shipment): bool
    {
        $row = $this->forOrder($orderId);
        if (!PendingShipment::is($row)) {
            return false;
        }
        $this->entity()->update((int)$row->id, $shipment + ['state' => PendingShipment::CREATED, 'reserved_at' => null, 'status_group' => self::initialGroup($shipment)]);
        $saved = $this->forOrder($orderId);

        // Дубль штрихкоду update() теж ковтає: успіх видно лише з перечитаного рядка.
        return $saved !== null && $saved->barcode === $shipment['barcode'] && $saved->state === PendingShipment::CREATED;
    }

    public function add(int $orderId, array $shipment): bool
    {
        return (bool)$this->entity()->add($shipment + [
            'status_group' => self::initialGroup($shipment),
            'order_id' => $orderId,
            'state' => PendingShipment::CREATED,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function rememberSender(int $orderId, string $senderUuid): void
    {
        $row = $this->forOrder($orderId);
        if (PendingShipment::is($row)) {
            $this->entity()->update((int)$row->id, ['sender_uuid' => $senderUuid]);
        }
    }

    public function release(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingShipment::is($row)) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function markUncertain(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingShipment::is($row)) {
            $this->entity()->update((int)$row->id, ['state' => PendingShipment::UNCERTAIN]);
        }
    }

    public function remove(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function dueForTracking(int $limit, string $createdSince, string $checkedBefore): array
    {
        return array_values($this->entity()->order('stale')->find(['trackable' => $createdSince, 'checked_before' => $checkedBefore, 'limit' => $limit]));
    }

    public function dueForLifecycle(int $limit, string $createdSince, string $checkedBefore): array
    {
        return array_values($this->entity()->order('lifecycle_stale')->find(['unseen' => $createdSince, 'lifecycle_checked_before' => $checkedBefore, 'limit' => $limit]));
    }

    public function saveEvent(int $orderId, array $event, string $checkedAt): void
    {
        $this->updateCreated($orderId, $event + ['checked_at' => $checkedAt]);
    }

    public function markChecked(int $orderId, string $checkedAt): void
    {
        $this->updateCreated($orderId, ['checked_at' => $checkedAt]);
    }

    public function saveLifecycle(int $orderId, string $status, string $checkedAt): void
    {
        $this->updateCreated($orderId, ['status' => $status, 'lifecycle_checked_at' => $checkedAt]);
    }

    /** @param array<string, mixed> $values */
    private function updateCreated(int $orderId, array $values): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null && !PendingShipment::is($row)) {
            $this->entity()->update((int)$row->id, $values + ['status_group' => ShipmentStatus::afterUpdate($row, $values)]);
        }
    }

    /** @param array<string, mixed> $shipment */
    private static function initialGroup(array $shipment): string
    {
        return ShipmentStatus::group(null, $shipment['status'] ?? null, null) ?? 'created';
    }

    private function entity(): ShipmentEntity
    {
        return $this->entityFactory->get(ShipmentEntity::class);
    }
}
