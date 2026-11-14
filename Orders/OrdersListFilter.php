<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Shipments\Barcode;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;

/**
 * Фільтр списку замовлень із частинами цього модуля: група статусів відправлення і ключове слово, яке є
 * штрихкодом. Модуль не може розширити фільтр ядра `keyword` (фільтр модуля замінює його повністю),
 * тому штрихкод перетворюється на id замовлення.
 */
final class OrdersListFilter
{
    /** GET-параметр групи у списку замовлень. */
    public const string PARAM = 'up_shipment';
    public const string NONE = 'none';

    /** @return ?string група з ShipmentStatus::FILTER_GROUPS або null */
    public static function group(mixed $raw): ?string
    {
        return is_string($raw) && isset(ShipmentStatus::FILTER_GROUPS[$raw]) ? $raw : null;
    }

    /**
     * @param callable(string): ?int $orderOfBarcode
     * @param int $moduleId id цього модуля: `none` — замовлення з його доставкою без відправлення
     */
    public static function apply(array $filter, ?string $group, ?string $keyword, callable $orderOfBarcode, int $moduleId): array
    {
        $barcode = is_string($keyword) ? Barcode::parse(trim($keyword)) : null;
        $orderId = $barcode !== null ? $orderOfBarcode($barcode) : null;
        // Не штрихкод цього магазину: пошук ядра лишає його (цифри в телефоні, коментарі чи назві товару).
        if ($orderId !== null) {
            unset($filter['keyword']);
            $filter = self::narrow($filter, [$orderId]);
        }
        $group = self::group($group);
        if ($group === self::NONE) {
            $filter['up_without_shipment'] = $moduleId;
        } elseif ($group !== null) {
            // Підзапит за індексованим status_group (OrdersEntityFilters), а не id кожного замовлення групи.
            $filter['up_status_groups'] = [$group];
        }

        return $filter;
    }

    /** @param list<int> $ids */
    private static function narrow(array $filter, array $ids): array
    {
        if (isset($filter['id'])) {
            $ids = array_values(array_intersect($ids, array_map(intval(...), (array)$filter['id'])));
        }
        // Порожній список означав би для фільтра ядра "без умови": [0] не збігається ні з чим.
        $filter['id'] = $ids === [] ? [0] : array_values(array_unique($ids));

        return $filter;
    }
}
