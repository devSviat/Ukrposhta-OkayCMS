<?php

namespace Okay\Modules\Sviat\Ukrposhta\Entities;

use Okay\Core\Entity\Entity;

/** Вкладка пунктів у кошику (напр. «Відділення»); `kinds` — JSON-список OfficeKind. */
class GroupEntity extends Entity
{
    protected static $fields = [
        'id',
        'position',
        'enabled',
        'kinds',
    ];

    protected static $langFields = [
        'name',
    ];

    protected static $defaultOrderFields = ['position', 'id'];
    protected static $table = 'sviat__ukrposhta__groups';
    protected static $langObject = 'group';
    protected static $langTable = 'sviat__ukrposhta__groups';
    protected static $tableAlias = 'upg';
}
