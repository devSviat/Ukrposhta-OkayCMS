<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Shipments\Barcode;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;

/** Частини фільтра списку замовлень, які хаб Sviat/DeliveryFilter делегує цьому модулю. */
final class OrdersListFilter
{
    public const string NONE = 'none';

    /** @return ?string група з ShipmentStatus::FILTER_GROUPS або null */
    public static function group(mixed $raw): ?string
    {
        return is_string($raw) && isset(ShipmentStatus::FILTER_GROUPS[$raw]) ? $raw : null;
    }

    /** @param int $moduleId id цього модуля: `none` — замовлення з його доставкою без відправлення */
    public static function byStatus(array $filter, mixed $group, int $moduleId): array
    {
        $group = self::group($group);
        if ($group === self::NONE) {
            $filter['up_without_shipment'] = $moduleId;
        } elseif ($group !== null) {
            // Підзапит за індексованим status_group (OrdersEntityFilters), а не id кожного замовлення групи.
            $filter['up_status_groups'] = [$group];
        }

        return $filter;
    }

    /** Штрихкод, якщо текст пошуку ним є. */
    public static function barcode(mixed $keyword): ?string
    {
        return is_string($keyword) ? Barcode::parse(trim($keyword)) : null;
    }
}
