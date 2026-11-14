<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Core\Modules\AbstractModuleEntityFilter;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\ShipmentEntity;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;

/**
 * Фільтри OrdersEntity для списку замовлень. Прості підзапити IN: Aura бере пари alias.column у лапки
 * всередині EXISTS (…), і SQL ламається. Значення в SQL — лише числа й групи з ShipmentStatus.
 */
class OrdersEntityFilters extends AbstractModuleEntityFilter
{
    /** @param int $moduleId замовлення з доставкою цього модуля без створеного відправлення */
    public function filter__up_without_shipment($moduleId, $filter)
    {
        $moduleId = (int)$moduleId;
        $orders = OrdersEntity::getTableAlias();
        // Самовивіз і доставки без модуля мають module_id = 0: невідомий id модуля не повинен їх зачепити.
        if ($moduleId <= 0) {
            $this->select->where('1 = 0');

            return;
        }
        $deliveries = DeliveriesEntity::getTable();
        $shipments = ShipmentEntity::getTable();
        // Резервація чи невідоме створення теж «без відправлення»: менеджер має їх побачити й розібрати.
        $this->select->where("{$orders}.delivery_id IN (SELECT id FROM {$deliveries} WHERE module_id = {$moduleId})")
            ->where("{$orders}.id NOT IN (SELECT order_id FROM {$shipments} WHERE state = '" . PendingShipment::CREATED . "')");
    }

    /** @param list<string> $groups замовлення, чиє відправлення в одній із цих груп */
    public function filter__up_status_groups($groups, $filter)
    {
        $known = array_diff(array_keys(ShipmentStatus::FILTER_GROUPS), [OrdersListFilter::NONE]);
        $groups = array_values(array_intersect($known, array_filter((array)$groups, is_string(...))));
        $list = $groups === [] ? "''" : implode(',', array_map(static fn(string $group): string => "'{$group}'", $groups));
        $orders = OrdersEntity::getTableAlias();
        $shipments = ShipmentEntity::getTable();
        $this->select->where("{$orders}.id IN (SELECT order_id FROM {$shipments} WHERE status_group IN ({$list}))");
    }
}
