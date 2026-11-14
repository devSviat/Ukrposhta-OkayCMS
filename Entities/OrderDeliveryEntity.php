<?php

namespace Okay\Modules\Sviat\Ukrposhta\Entities;

use Okay\Core\Entity\Entity;

/**
 * Що покупець обрав для замовлення, з копіями назв: пізніші зміни довідника не повинні переписувати історію.
 * Один рядок на замовлення (`order_id` унікальний); `id` потрібен базовому Entity для update().
 */
class OrderDeliveryEntity extends Entity
{
    protected static $fields = [
        'id',
        'order_id',
        'delivery_id',
        'city_id',
        'city_name',
        'office_id',
        'office_postcode',
        'office_kind',
        'office_title',
        'office_address',
        'price',
        'price_source',
        'cod',
        'cod_fee',
        'created_at',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__ukrposhta__order_delivery';
    protected static $tableAlias = 'upod';
}
