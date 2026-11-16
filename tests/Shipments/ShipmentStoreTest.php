<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\ShipmentEntity;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentCard;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStore;
use PHPUnit\Framework\TestCase;

class ShipmentStoreTest extends TestCase
{
    /** Сторінка списку замовлень не тягне JSON знімка й історії: рядку вистачає штрихкоду, групи й події. */
    public function testOrdersListReadsOnlyTheColumnsOfTheRow(): void
    {
        $entity = $this->createMock(ShipmentEntity::class);
        $entity->expects(self::once())->method('cols')->with(ShipmentStore::LIST_COLUMNS)->willReturnSelf();
        $entity->method('find')->willReturn([(object)['order_id' => 7, 'barcode' => '0500000000017']]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        self::assertSame([7], array_keys(new ShipmentStore($factory)->forOrders([7])));
        self::assertNotContains('details', ShipmentStore::LIST_COLUMNS);
        self::assertNotContains('history', ShipmentStore::LIST_COLUMNS);
    }

    /** Колонок вистачає рядку списку: без жодної з них бейдж чи підказка стали б іншими. */
    public function testListColumnsAreEnoughForTheListRow(): void
    {
        $values = [
            'order_id' => 7, 'barcode' => '0500000000017', 'state' => PendingShipment::CREATED, 'status' => 'REGISTERED',
            'status_group' => null, 'event_code' => 41000, 'event_name' => 'Вручено', 'event_reason' => 10,
        ];
        $row = (object)array_intersect_key($values, array_flip(ShipmentStore::LIST_COLUMNS));
        $card = ShipmentCard::fromRow($row);

        self::assertFalse(PendingShipment::is($row));
        self::assertSame(['0500000000017', 'returned', 'Вручено'], [$card['barcode'], $card['statusGroup'], $card['eventName']]);
    }
}
