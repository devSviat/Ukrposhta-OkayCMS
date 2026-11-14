<?php

namespace Okay\Modules\Sviat\Ukrposhta\Entities;

use Okay\Core\Entity\Entity;

/** Населені пункти, де є хоча б один пункт Укрпошти; `id` — id міста класифікатора. */
class CityEntity extends Entity
{
    protected static $fields = [
        'id',
        'region_id',
        'name_ua',
        'type_ua',
        'district_ua',
        'region_ua',
        'search_text',
        'points',
        'row_hash',
        'seen_run',
        'updated_at',
    ];

    protected static $defaultOrderFields = ['name_ua', 'id'];
    protected static $table = 'sviat__ukrposhta__cities';
    protected static $tableAlias = 'upc';
}
