<?php

namespace Okay\Modules\Sviat\Ukrposhta\Storage;

use Okay\Core\Entity\Entity;

class StateEntity extends Entity
{
    protected static $fields = [
        'id',
        'name',
        'value',
        'updated_at',
    ];

    protected static $table = 'sviat__ukrposhta__state';
    protected static $tableAlias = 'ups';
    protected static $alternativeIdField = 'name';
}
