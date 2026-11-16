<?php

namespace Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersListFilter;
use PHPUnit\Framework\TestCase;

class OrdersListFilterTest extends TestCase
{
    private const int MODULE = 42;

    public function testBarcodeIsRecognised(): void
    {
        self::assertSame('0500000000017', OrdersListFilter::barcode(' 05000 0000 0017 '));
        self::assertSame('RR123456789UA', OrdersListFilter::barcode('rr123456789ua'));
        foreach (['Олена', '0500', '20451234567890', null, ['0500000000017']] as $keyword) {
            self::assertNull(OrdersListFilter::barcode($keyword));
        }
    }

    public function testGroupFilter(): void
    {
        self::assertSame(['up_status_groups' => ['in_transit']], OrdersListFilter::byStatus([], 'in_transit', self::MODULE));
        self::assertSame(['up_without_shipment' => self::MODULE], OrdersListFilter::byStatus([], 'none', self::MODULE));
        self::assertSame(['page' => 1], OrdersListFilter::byStatus(['page' => 1], null, self::MODULE));
    }

    public function testGroupComesOnlyFromTheList(): void
    {
        self::assertSame('received', OrdersListFilter::group('received'));
        self::assertSame('none', OrdersListFilter::group('none'));
        foreach (['', 'RECEIVED', "received' OR 1=1", ['received'], null, 3] as $raw) {
            self::assertNull(OrdersListFilter::group($raw));
            self::assertSame([], OrdersListFilter::byStatus([], $raw, self::MODULE));
        }
    }
}
