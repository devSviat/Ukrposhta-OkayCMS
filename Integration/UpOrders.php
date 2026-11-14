<?php

namespace Okay\Modules\Sviat\Ukrposhta\Integration;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\ShipmentEntity;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentTracking;

/**
 * Контракт лише на читання для інших модулів (Checkbox, Messaging, OrdersExport, LiqPay). Усе поза
 * Integration\ може змінитися без попередження; цей клас і його value objects — ні.
 *
 * Використовуйте його лише тоді, коли модуль активний: class_exists(UpOrders::class) і
 * Modules::isActiveModule('Sviat', 'Ukrposhta'); вимкнений модуль не реєструє сервісів.
 */
final class UpOrders
{
    private const array SHIPMENT_COLUMNS = ['order_id', 'barcode', 'state', 'status_group', 'event_at', 'cod_amount'];
    private const array AT_OFFICE = ['arrived', 'storage'];

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, UpShipment> за id замовлення; відправлення без штрихкоду (ще не створені) пропущено
     */
    public function shipments(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        return $this->build($this->shipmentEntity()->find(['order_id' => $orderIds]));
    }

    /** @return array<int, UpShipment> відправлення, які Укрпошта ще везе (не фінальні, штрихкод відомий) */
    public function unfinished(): array
    {
        $since = date('Y-m-d H:i:s', time() - ShipmentTracking::TRACK_FOR_DAYS * 86400);

        return $this->build($this->shipmentEntity()->find(['trackable' => $since]));
    }

    /**
     * Замовлення, чию посилку вручено $since або пізніше. Відправлення, яке eCom позначила DELIVERED
     * раніше за трекінг, не має event_at, тому дата береться з останньої перевірки життєвого циклу.
     *
     * @return list<int>
     */
    public function receivedSince(string $since): array
    {
        $ids = $this->entityFactory->get(ShipmentEntity::class)->noLimit()->cols(['order_id'])->find(['received_since' => $since]);

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /** Null, якщо менеджер змінив доставку замовлення: знімок описує вже не його. */
    public function addressOf(int $orderId): ?UpAddress
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->cols(['id', 'delivery_id'])->get($orderId);
        $delivery = $this->entityFactory->get(OrderDeliveryEntity::class)->findOne(['order_id' => $orderId]);
        if (!$order || !$delivery || (int)$delivery->delivery_id !== (int)$order->delivery_id) {
            return null;
        }

        return self::address($delivery);
    }

    /** Повний підзапит, а не назва таблиці: споживачі не мають знати її колонок. */
    public static function withBarcodeSql(): string
    {
        return "SELECT order_id FROM " . ShipmentEntity::getTable()
            . " WHERE state = '" . PendingShipment::CREATED . "' AND barcode IS NOT NULL AND barcode <> ''";
    }

    /** @param ?object $delivery знімок доставки замовлення: його `cod` вирішує, коли суми накладеного платежу в рядка немає */
    public static function shipment(object $row, ?object $delivery): ?UpShipment
    {
        $barcode = trim((string)($row->barcode ?? ''));
        if (($row->state ?? null) !== PendingShipment::CREATED || $barcode === '') {
            return null;
        }
        $group = ($row->status_group ?? null) !== null ? (string)$row->status_group : null;
        $statusAt = self::date($row->event_at ?? null);
        $cod = ($row->cod_amount ?? null) !== null ? (float)$row->cod_amount > 0 : !empty($delivery->cod ?? null);

        return new UpShipment((int)$row->order_id, $barcode, $group, $statusAt, in_array($group, self::AT_OFFICE, true) ? $statusAt : null, $cod);
    }

    public static function address(?object $delivery): ?UpAddress
    {
        if ($delivery === null) {
            return null;
        }
        $text = static fn(string $name): string => trim((string)($delivery->$name ?? ''));
        // cityName() дописує через кому район і область; адресі потрібні лише тип і назва.
        $city = trim(explode(', ', $text('city_name'))[0]);
        $line = implode(', ', array_filter([$text('office_title'), $text('office_address')], static fn(string $p): bool => $p !== ''));

        return new UpAddress($city, $line, $text('office_postcode'));
    }

    private function shipmentEntity(): ShipmentEntity
    {
        return $this->entityFactory->get(ShipmentEntity::class)->noLimit()->cols(self::SHIPMENT_COLUMNS);
    }

    /** @param iterable<object> $rows */
    private function build(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        $orderIds = array_map(static fn(object $r): int => (int)$r->order_id, $rows);
        $deliveries = $orderIds === [] ? [] : $this->entityFactory->get(OrderDeliveryEntity::class)->mappedBy('order_id')->noLimit()->cols(['order_id', 'cod'])->find(['order_id' => $orderIds]);
        $result = [];
        foreach ($rows as $row) {
            $shipment = self::shipment($row, $deliveries[(int)$row->order_id] ?? null);
            if ($shipment !== null) {
                $result[$shipment->orderId] = $shipment;
            }
        }

        return $result;
    }

    private static function date(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }
}
