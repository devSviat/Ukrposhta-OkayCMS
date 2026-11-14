<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/**
 * Де зберігаються відправлення замовлень: одне на замовлення.
 *
 * Поля відправлення в $shipment: uuid, barcode, status (lifecycle eCom), delivery_price, cod_amount, cod_fee, paid_by_recipient (null — невідомо).
 */
interface ShipmentRows
{
    public function forOrder(int $orderId): ?object;

    /**
     * Рядки сторінки списку замовлень одним запитом.
     *
     * @param list<int> $orderIds
     * @return array<int, object> за id замовлення
     */
    public function forOrders(array $orderIds): array;

    public function orderOfBarcode(string $barcode): ?int;

    /**
     * Займає замовлення до першого запиту до Укрпошти: унікальний order_id пропускає лише один із двох
     * одночасних запитів. False, коли замовлення вже має рядок у будь-якому стані.
     */
    public function reserve(int $orderId): bool;

    /**
     * Перетворює резервацію чи невідомий рядок на відправлення. False, коли запис не вдався.
     *
     * @param array{uuid: string, barcode: string, status: ?string, delivery_price: ?float, cod_amount: ?float, cod_fee: ?float, paid_by_recipient: ?bool} $shipment
     */
    public function complete(int $orderId, array $shipment): bool;

    /**
     * Прив'язує відправлення, створене деінде. False, коли замовлення чи штрихкод уже зайняті.
     *
     * @param array{uuid: string, barcode: string, status: ?string, delivery_price: ?float, cod_amount: ?float, cod_fee: ?float, paid_by_recipient: ?bool} $shipment
     */
    public function add(int $orderId, array $shipment): bool;

    /** Клієнт-відправник запиту, що зараз піде: пошук невідомого створення шукає саме серед його відправлень. */
    public function rememberSender(int $orderId, string $senderUuid): void;

    /** Знімає резервацію; рядок відправлення лишається. */
    public function release(int $orderId): void;

    /** Запит до Укрпошти пішов без відповіді: замовлення чекає на пошук чи прив'язку. */
    public function markUncertain(int $orderId): void;

    public function remove(int $orderId): void;

    /**
     * Створені відправлення, які трекінг ще має опитувати: зі штрихкодом, нефінальні, створені не раніше
     * `$createdSince`, востаннє опитані до `$checkedBefore`. Спершу ті, що найдовше не опитувалися.
     *
     * @param string $createdSince Y-m-d H:i:s
     * @param string $checkedBefore Y-m-d H:i:s
     * @return list<object>
     */
    public function dueForTracking(int $limit, string $createdSince, string $checkedBefore): array;

    /**
     * Створені відправлення без жодної події трекінгу, які в eCom лишаються CREATED, востаннє
     * перевірені за lifecycle до `$checkedBefore`.
     *
     * @return list<object>
     */
    public function dueForLifecycle(int $limit, string $createdSince, string $checkedBefore): array;

    /**
     * Записує подію трекінгу й час опитування.
     *
     * @param array{event_code: int, event_name: string, event_at: string, event_reason: ?int, status_group?: ?string} $event
     */
    public function saveEvent(int $orderId, array $event, string $checkedAt): void;

    /** Відправлення опитано, а події немає: рядок іде в кінець черги. */
    public function markChecked(int $orderId, string $checkedAt): void;

    /** Статус eCom (lifecycle) і час його перевірки. */
    public function saveLifecycle(int $orderId, string $status, string $checkedAt): void;
}
