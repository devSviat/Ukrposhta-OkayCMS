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

        self::assertSame([...ShipmentEntity::getFields(), ...ShipmentEntity::LATE_FIELDS], $columns);
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

    /**
     * Колонки 1.3.0 entity читає лише після оновлення модуля: до нього SELECT з ними впав би, ядро сховало б
     * помилку, і замовлення виглядало б без відправлення — менеджер створив би друге.
     */
    public function testLateColumnsAreNotSelectedBeforeTheUpdate(): void
    {
        self::assertSame(['details', 'history'], ShipmentEntity::LATE_FIELDS);
        self::assertSame([], array_intersect(ShipmentEntity::LATE_FIELDS, ShipmentEntity::getAllDefaultFields()));
        self::assertSame(ShipmentEntity::LATE_FIELDS, array_map(static fn(EntityField $f): string => $f->getName(), Init::lateShipmentColumns()));
        foreach (Init::lateShipmentColumns() as $field) {
            self::assertTrue($field->isNullable(), $field->getName());
        }
        self::assertSame([EntityField::TYPE_TEXT, EntityField::TYPE_MEDIUMTEXT], array_map(static fn(EntityField $f): string => $f->getType(), Init::lateShipmentColumns()));
    }

    /** Ядро ковтає помилку ALTER: прапорець, що вмикає читання колонок, ставиться лише коли таблиця їх справді має. */
    public function testLateColumnsFlagOnlyWhenTheTableHasThem(): void
    {
        $migrated = [];
        $flagged = 0;
        $migrate = static function (EntityField $field) use (&$migrated): void {
            $migrated[] = $field->getName();
        };
        $flag = static function () use (&$flagged): void {
            $flagged++;
        };

        self::assertFalse(Init::addLateShipmentColumnsWith($migrate, static fn(): array => ['id', 'order_id', 'details'], $flag), 'ALTER of history failed');
        self::assertSame(0, $flagged);
        self::assertSame(['details', 'history'], $migrated);

        self::assertTrue(Init::addLateShipmentColumnsWith($migrate, static fn(): array => ['id', 'order_id', 'details', 'history'], $flag));
        self::assertSame(1, $flagged);
    }

    /** Менеджер, що не натиснув «Оновити», не повинен втратити запис відправлення через невідому колонку. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testStoreWritesLateColumnsOnlyOnceRegistered(): void
    {
        $values = ['barcode' => '0500000000017', 'details' => '{}', 'history' => '[]'];

        self::assertSame(['barcode' => '0500000000017'], ShipmentEntity::knownColumns($values));
        foreach (ShipmentEntity::LATE_FIELDS as $field) {
            ShipmentEntity::addField($field);
        }
        self::assertSame($values, ShipmentEntity::knownColumns($values));
    }

    /** Installer виконує update_x_y_z лише до версії з module.json: метод новішої версії мовчки не запуститься. */
    public function testEveryUpdateMethodIsWithinTheModuleVersion(): void
    {
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Init/module.json'), true);
        $updates = [];
        foreach (new \ReflectionClass(Init::class)->getMethods() as $method) {
            if (preg_match('/^update_(\d+)_(\d+)_(\d+)$/', $method->name, $m)) {
                $updates[] = "{$m[1]}.{$m[2]}.{$m[3]}";
            }
        }

        self::assertContains('1.3.0', $updates);
        foreach ($updates as $version) {
            self::assertTrue(version_compare($version, $manifest['version'], '<='), $version);
        }
    }
}
