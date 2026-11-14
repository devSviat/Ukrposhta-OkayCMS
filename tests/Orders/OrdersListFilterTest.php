<?php

namespace Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersListFilter;
use PHPUnit\Framework\TestCase;

class OrdersListFilterTest extends TestCase
{
    private const int MODULE = 42;

    private static function apply(array $filter, ?string $group, ?string $keyword, array $barcodes = []): array
    {
        return OrdersListFilter::apply($filter, $group, $keyword, static fn(string $barcode): ?int => $barcodes[$barcode] ?? null, self::MODULE);
    }

    public function testBarcodeKeywordBecomesIdFilter(): void
    {
        $filter = ['page' => 1, 'limit' => 40, 'keyword' => ' 05000 0000 0017 '];

        $applied = self::apply($filter, null, $filter['keyword'], ['0500000000017' => 77]);

        self::assertSame(['page' => 1, 'limit' => 40, 'id' => [77]], $applied);
        self::assertSame(['id' => [77]], array_intersect_key(self::apply(['keyword' => 'rr123456789ua'], null, 'rr123456789ua', ['RR123456789UA' => 77]), ['id' => 1, 'keyword' => 1]));
    }

    public function testUnknownBarcodeAndOtherTextStayCoreSearch(): void
    {
        self::assertSame(['keyword' => '0500000000017'], self::apply(['keyword' => '0500000000017'], null, '0500000000017'));
        self::assertSame(['keyword' => 'Олена'], self::apply(['keyword' => 'Олена'], null, 'Олена', ['0500000000017' => 77]));
    }

    public function testBarcodeNarrowsAnExistingIdFilter(): void
    {
        self::assertSame(['id' => [0]], self::apply(['id' => [5, 6], 'keyword' => '0500000000017'], null, '0500000000017', ['0500000000017' => 77]));
    }

    public function testGroupFilter(): void
    {
        self::assertSame(['up_status_groups' => ['in_transit']], self::apply([], 'in_transit', null));
        self::assertSame(['up_without_shipment' => self::MODULE], self::apply([], 'none', null));
        self::assertSame([], self::apply([], null, null));
    }

    public function testGroupComesOnlyFromTheList(): void
    {
        self::assertSame('received', OrdersListFilter::group('received'));
        self::assertSame('none', OrdersListFilter::group('none'));
        foreach (['', 'RECEIVED', "received' OR 1=1", ['received'], null, 3] as $raw) {
            self::assertNull(OrdersListFilter::group($raw));
        }
    }
}
