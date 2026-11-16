<?php

namespace Okay\Modules\Sviat\Ukrposhta\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;

/**
 * Відправлення Укрпошти для замовлення, одне на замовлення (`order_id` унікальний, `barcode` теж).
 * Рядок з'являється до запиту до Укрпошти як резервація: див. PendingShipment.
 */
class ShipmentEntity extends Entity
{
    /**
     * Колонки 1.3.0 (JSON ShipmentDetails і ShipmentHistory). Їх немає в `$fields`: до оновлення модуля в таблиці їх
     * немає, а SELECT із ними впав би мовчки й сховав відправлення. Init::init() реєструє їх після update_1_3_0().
     */
    public const array LATE_FIELDS = ['details', 'history'];
    /** Налаштування, яке ставить update_1_3_0() (та install()): колонки вже в таблиці. */
    public const string LATE_FIELDS_SETTING = 'sviat__ukrposhta__shipment_columns';

    protected static $fields = [
        'id',
        'order_id',
        'uuid',
        'sender_uuid',
        'barcode',
        'state',
        'status',
        'status_group',
        'event_code',
        'event_name',
        'event_at',
        'event_reason',
        'delivery_price',
        'cod_amount',
        'cod_fee',
        'paid_by_recipient',
        'created_at',
        'checked_at',
        'lifecycle_checked_at',
        'reserved_at',
    ];

    /**
     * Лише колонки, які entity знає: ядро пише в INSERT/UPDATE усі ключі, і невідома колонка провалила б увесь запис.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function knownColumns(array $values): array
    {
        return array_intersect_key($values, array_flip(static::getAllDefaultFields()));
    }

    /** Відправлення, які трекінг ще має опитувати: зі штрихкодом, без фінальної групи, створені не раніше `since`. */
    protected function filter__trackable(string $since): void
    {
        $alias = $this->getTableAlias();
        $final = implode(',', array_map(static fn(string $g): string => "'{$g}'", ShipmentStatus::FINAL));
        $this->select->where("{$alias}.state = '" . PendingShipment::CREATED . "'")
            ->where("{$alias}.barcode IS NOT NULL")
            ->where("{$alias}.created_at >= :up_trackable_since")
            // Явний IS NULL: NOT IN із NULL дало б NULL, і рядок без групи випав би з вибірки.
            ->where("({$alias}.status_group IS NULL OR {$alias}.status_group NOT IN ({$final}))")
            ->bindValue('up_trackable_since', $since);
    }

    /** Вручені відправлення; у тих, що eCom позначила DELIVERED раніше за трекінг, event_at порожній. */
    protected function filter__received_since(string $since): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("{$alias}.status_group = 'received'")
            ->where("COALESCE({$alias}.event_at, {$alias}.lifecycle_checked_at) >= :up_received_since")
            ->bindValue('up_received_since', $since);
    }

    protected function filter__checked_before(string $before): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("({$alias}.checked_at IS NULL OR {$alias}.checked_at < :up_checked_before)")
            ->bindValue('up_checked_before', $before);
    }

    /** Створене відправлення, якого трекінг ще не бачив і яке в eCom досі CREATED (чи статус не відомий). */
    protected function filter__unseen(string $since): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("{$alias}.state = '" . PendingShipment::CREATED . "'")
            ->where("{$alias}.uuid IS NOT NULL")
            ->where("{$alias}.event_code IS NULL")
            ->where("({$alias}.status IS NULL OR {$alias}.status = 'CREATED')")
            ->where("{$alias}.created_at >= :up_unseen_since")
            ->bindValue('up_unseen_since', $since);
    }

    protected function filter__lifecycle_checked_before(string $before): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("({$alias}.lifecycle_checked_at IS NULL OR {$alias}.lifecycle_checked_at < :up_lifecycle_before)")
            ->bindValue('up_lifecycle_before', $before);
    }

    /** `stale`: спершу ті, що найдовше не опитувалися. */
    protected function customOrder($order = null, array $orderFields = [], array $additionalData = [])
    {
        if ($order === 'stale') {
            $orderFields = ['checked_at', 'id'];
        } elseif ($order === 'lifecycle_stale') {
            $orderFields = ['lifecycle_checked_at', 'id'];
        }

        return parent::customOrder($order, $orderFields, $additionalData);
    }

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__ukrposhta__shipments';
    protected static $tableAlias = 'upsh';
}
