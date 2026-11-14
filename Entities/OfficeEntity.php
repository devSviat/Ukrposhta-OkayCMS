<?php

namespace Okay\Modules\Sviat\Ukrposhta\Entities;

use Okay\Core\Entity\Entity;

/** Відділення, поштомати й пункти PUDO Укрпошти, у які кошик продає доставку; `id` — id класифікатора. */
class OfficeEntity extends Entity
{
    protected static $fields = [
        'id',
        'region_id',
        'city_id',
        'postcode',
        'postindex',
        'kind',
        'name_ua',
        'address_ua',
        'lat',
        'lng',
        'subtype_id',
        'is_cash',
        'is_automated',
        'schedule_json',
        'row_hash',
        'seen_run',
        'updated_at',
    ];

    protected static $defaultOrderFields = ['postcode', 'id'];
    protected static $table = 'sviat__ukrposhta__offices';
    protected static $tableAlias = 'upo';
}
