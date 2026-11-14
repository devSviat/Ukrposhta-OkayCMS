<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShipmentStatusTest extends TestCase
{
    /** @return iterable<string, array{?int, ?string, ?int, ?string}> */
    public static function cases(): iterable
    {
        yield 'created event' => [10601, null, null, 'created'];
        yield 'created lifecycle' => [null, 'CREATED', null, 'created'];
        yield 'accepted event' => [10100, null, null, 'accepted'];
        yield 'accepted lifecycle' => [null, 'REGISTERED', null, 'accepted'];
        foreach ([20700, 20800, 20900, 21500, 31300] as $code) {
            yield "in transit {$code}" => [$code, null, null, 'in_transit'];
        }
        foreach (['DELIVERING', 'FORWARDING'] as $lifecycle) {
            yield "in transit {$lifecycle}" => [null, $lifecycle, null, 'in_transit'];
        }
        yield 'problem 31100' => [31100, null, null, 'problem'];
        yield 'problem 31400' => [31400, null, null, 'problem'];
        yield 'arrived event' => [21700, null, null, 'arrived'];
        yield 'arrived lifecycle' => [null, 'IN_DEPARTMENT', null, 'arrived'];
        yield 'storage event' => [21400, null, null, 'storage'];
        yield 'storage lifecycle' => [null, 'STORAGE', null, 'storage'];
        yield 'received 41000' => [41000, null, null, 'received'];
        yield 'received 41000 other reason' => [41000, null, 3, 'received'];
        yield 'received 48000' => [48000, null, null, 'received'];
        yield 'received lifecycle' => [null, 'DELIVERED', null, 'received'];
        yield 'returning event' => [31200, null, null, 'returning'];
        yield 'returning lifecycle' => [null, 'RETURNING', null, 'returning'];
        yield 'returned by reason 10' => [41000, null, 10, 'returned'];
        yield 'returned lifecycle' => [null, 'RETURNED', null, 'returned'];
        foreach ([10600, 10602, 10603] as $code) {
            yield "cancelled {$code}" => [$code, null, null, 'cancelled'];
        }
        foreach (['CANCELED', 'DELETED'] as $lifecycle) {
            yield "cancelled {$lifecycle}" => [null, $lifecycle, null, 'cancelled'];
        }
        yield 'unknown event' => [99999, null, null, 'in_transit'];
        yield 'event beats stale lifecycle' => [21700, 'CREATED', null, 'arrived'];
        yield 'nothing known' => [null, null, null, null];
        yield 'unknown lifecycle' => [null, 'SOMETHING', null, null];
    }

    #[DataProvider('cases')]
    public function testGroup(?int $event, ?string $lifecycle, ?int $reason, ?string $expected): void
    {
        self::assertSame($expected, ShipmentStatus::group($event, $lifecycle, $reason));
    }

    public function testFinalAndFilterGroups(): void
    {
        self::assertSame(['received', 'returned', 'cancelled'], ShipmentStatus::FINAL);
        self::assertSame(
            ['created', 'accepted', 'in_transit', 'problem', 'arrived', 'storage', 'received', 'returning', 'returned', 'cancelled', 'none'],
            array_keys(ShipmentStatus::FILTER_GROUPS),
        );
    }

    public function testHandoverAfterReturningIsReturned(): void
    {
        self::assertSame('returned', ShipmentStatus::group(41000, null, null, 'returning'));
        self::assertSame('returned', ShipmentStatus::group(41000, null, null, 'returned'));
        self::assertSame('received', ShipmentStatus::group(41000, null, null, 'arrived'));
        self::assertSame('received', ShipmentStatus::group(41000, null, null, null));
    }

    public function testAfterUpdateUsesPreviousGroupAndRespectsExplicit(): void
    {
        $row = (object)['event_code' => 31200, 'status' => 'REGISTERED', 'event_reason' => null, 'status_group' => 'returning'];

        self::assertSame('returned', ShipmentStatus::afterUpdate($row, ['event_code' => 41000]));
        self::assertSame('returning', ShipmentStatus::afterUpdate($row, ['checked_at' => 'x']));
        self::assertSame('arrived', ShipmentStatus::afterUpdate($row, ['status_group' => 'arrived']));
        self::assertNull(ShipmentStatus::afterUpdate((object)[], []));
    }
}
