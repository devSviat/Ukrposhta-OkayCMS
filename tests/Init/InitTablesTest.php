<?php

namespace Modules\Sviat\Ukrposhta\Init;

use Okay\Core\Modules\EntityField;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\ShipmentEntity;
use Okay\Modules\Sviat\Ukrposhta\Init\Init;
use PHPUnit\Framework\TestCase;

class InitTablesTest extends TestCase
{
    public function testInstallCreatesModuleTables(): void
    {
        $tables = array_map(static fn(string $entity): string => $entity::getTable(), array_keys(Init::tables()));

        self::assertSame([
            '__sviat__ukrposhta__state',
            '__sviat__ukrposhta__cities',
            '__sviat__ukrposhta__offices',
            '__sviat__ukrposhta__groups',
            '__sviat__ukrposhta__order_delivery',
            '__sviat__ukrposhta__shipments',
        ], $tables);
    }

    /** Кожна колонка таблиці є полем entity: інакше add()/update() мовчки її пропускають. */
    public function testOrderDeliveryColumnsMatchTheEntity(): void
    {
        $columns = array_map(static fn($field): string => $field->getName(), Init::tables()[OrderDeliveryEntity::class]);

        self::assertSame(OrderDeliveryEntity::getFields(), $columns);
        self::assertContains('order_id', $columns);
    }

    /** Унікальні order_id і barcode — і є захист від другого відправлення: перевірка в коді гонку не ловить. */
    public function testShipmentColumnsMatchTheEntityAndGuardDuplicates(): void
    {
        $fields = Init::tables()[ShipmentEntity::class];
        $columns = array_map(static fn($field): string => $field->getName(), $fields);

        self::assertSame(ShipmentEntity::getFields(), $columns);
        self::assertContains('sender_uuid', $columns);
        self::assertContains('paid_by_recipient', $columns);
        $unique = array_values(array_map(
            static fn($field): string => $field->getName(),
            array_filter($fields, static fn(EntityField $field): bool => isset($field->getIndexes()[EntityField::INDEX_UNIQUE])),
        ));
        self::assertSame(['order_id', 'barcode'], $unique);
    }

    public function testNoLegacyFilesRemain(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta';

        foreach (['DTO', 'VO', 'Helpers', 'Plugins', 'Init/seed_demo_data.php'] as $legacy) {
            self::assertFileDoesNotExist("{$root}/{$legacy}");
        }
    }
}
