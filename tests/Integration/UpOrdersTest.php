<?php

namespace Modules\Sviat\Ukrposhta\Integration;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Integration\UpOrders;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use PHPUnit\Framework\TestCase;

class UpOrdersTest extends TestCase
{
    private static function row(array $over = []): object
    {
        return (object)($over + [
            'order_id' => 7, 'barcode' => '0501234567890', 'state' => 'created', 'status_group' => 'in_transit',
            'event_at' => '2026-11-10 12:00:00', 'cod_amount' => null,
        ]);
    }

    public function testPendingAndUncertainAreSkipped(): void
    {
        self::assertNull(UpOrders::shipment(self::row(['state' => 'pending']), null));
        self::assertNull(UpOrders::shipment(self::row(['state' => 'uncertain']), null));
        self::assertNull(UpOrders::shipment(self::row(['barcode' => '']), null));
        self::assertNull(UpOrders::shipment(self::row(['barcode' => null]), null));
    }

    public function testCarriesBarcodeGroupAndDate(): void
    {
        $shipment = UpOrders::shipment(self::row(), null);

        self::assertSame(7, $shipment->orderId);
        self::assertSame('0501234567890', $shipment->barcode);
        self::assertSame('in_transit', $shipment->group);
        self::assertSame('2026-11-10 12:00:00', $shipment->statusAt);
    }

    public function testArrivedAtOnlyAtOffice(): void
    {
        self::assertSame('2026-11-10 12:00:00', UpOrders::shipment(self::row(['status_group' => 'arrived']), null)->arrivedAt);
        self::assertSame('2026-11-10 12:00:00', UpOrders::shipment(self::row(['status_group' => 'storage']), null)->arrivedAt);
        self::assertNull(UpOrders::shipment(self::row(['status_group' => 'in_transit']), null)->arrivedAt);
        self::assertNull(UpOrders::shipment(self::row(['status_group' => null, 'event_at' => null]), null)->group);
    }

    public function testCodFromRowThenSnapshot(): void
    {
        self::assertFalse(UpOrders::shipment(self::row(['cod_amount' => 0]), (object)['cod' => 1])->cod);
        self::assertTrue(UpOrders::shipment(self::row(['cod_amount' => '250.00']), (object)['cod' => 0])->cod);
        self::assertTrue(UpOrders::shipment(self::row(['cod_amount' => null]), (object)['cod' => 1])->cod);
        self::assertFalse(UpOrders::shipment(self::row(['cod_amount' => null]), null)->cod);
    }

    public function testReturnedIsNotReceived(): void
    {
        self::assertSame('returned', ShipmentStatus::group(41000, null, 10));
        self::assertSame('returned', ShipmentStatus::group(41000, null, null, 'returning'));
        self::assertSame('returned', UpOrders::shipment(self::row(['status_group' => 'returned']), null)->group);
    }

    public function testAddressCityIsFirstSegment(): void
    {
        $address = UpOrders::address((object)[
            'city_name' => 'м. Вигадане, Тестовий р-н, Тестова обл.', 'office_title' => 'Відділення 1',
            'office_address' => 'вул. Тестова, 1', 'office_postcode' => '01001',
        ]);

        self::assertSame('м. Вигадане', $address->city);
        self::assertSame('Відділення 1, вул. Тестова, 1', $address->line);
        self::assertSame('01001', $address->postcode);
        self::assertSame('Відділення 1', UpOrders::address((object)['city_name' => 'с. Тест', 'office_title' => 'Відділення 1', 'office_address' => null, 'office_postcode' => '02002'])->line);
        self::assertNull(UpOrders::address(null));
    }

    public function testAddressNullForForeignDelivery(): void
    {
        self::assertNull($this->facade(orderDelivery: 3, snapshotDelivery: 5)->addressOf(7));
        self::assertSame('м. Тест', $this->facade(orderDelivery: 5, snapshotDelivery: 5)->addressOf(7)?->city);
    }

    public function testWithBarcodeSqlShape(): void
    {
        self::assertSame(
            "SELECT order_id FROM __sviat__ukrposhta__shipments WHERE state = 'created' AND barcode IS NOT NULL AND barcode <> ''",
            UpOrders::withBarcodeSql(),
        );
    }

    private function facade(int $orderDelivery, int $snapshotDelivery): UpOrders
    {
        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('cols')->willReturnSelf();
        $orders->method('get')->willReturn((object)['delivery_id' => $orderDelivery]);
        $snapshots = $this->createStub(OrderDeliveryEntity::class);
        $snapshots->method('findOne')->willReturn((object)[
            'delivery_id' => $snapshotDelivery, 'city_name' => 'м. Тест, р-н, обл.', 'office_title' => 'В1',
            'office_address' => null, 'office_postcode' => '03003',
        ]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => $class === OrdersEntity::class ? $orders : $snapshots);

        return new UpOrders($factory);
    }
}
