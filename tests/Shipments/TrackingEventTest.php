<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\TrackingEvent;
use PHPUnit\Framework\TestCase;

class TrackingEventTest extends TestCase
{
    public function testStringEventAndSortByDate(): void
    {
        $late = TrackingEvent::fromApi(['step' => 1, 'event' => '41000', 'name' => 'Вручено', 'date' => '2026-11-10T15:00:00', 'eventReason_id' => 3]);
        $early = TrackingEvent::fromApi(['step' => 9, 'event' => 20700, 'name' => 'У дорозі', 'date' => '2026-11-08T09:30:00']);

        self::assertSame(41000, $late->code);
        self::assertSame(20700, $early->code);
        self::assertSame(3, $late->reason);
        self::assertSame('Вручено', $late->name);
        $history = TrackingEvent::history([
            ['step' => 1, 'event' => '41000', 'name' => 'Вручено', 'date' => '2026-11-10T15:00:00'],
            ['step' => 9, 'event' => 20700, 'name' => 'У дорозі', 'date' => '2026-11-08T09:30:00'],
        ]);
        self::assertSame([20700, 41000], array_map(static fn(TrackingEvent $e): ?int => $e->code, $history));
    }

    public function testDateWithoutTimezoneIsKyiv(): void
    {
        $event = TrackingEvent::fromApi(['event' => 10100, 'date' => '2026-07-01T12:00:00']);

        self::assertSame(new \DateTimeImmutable('2026-07-01 12:00:00', new \DateTimeZone('Europe/Kyiv'))->getTimestamp(), $event->timestamp);
    }

    public function testDateWithOffsetIsKept(): void
    {
        $event = TrackingEvent::fromApi(['event' => 10100, 'date' => '2026-07-01T12:00:00+00:00']);

        self::assertSame(new \DateTimeImmutable('2026-07-01 12:00:00 UTC')->getTimestamp(), $event->timestamp);
    }

    public function testMalformedInputGivesNullFields(): void
    {
        self::assertNull(TrackingEvent::fromApi(['event' => 'x', 'date' => '2026-07-01T12:00:00'])->code);
        self::assertNull(TrackingEvent::fromApi(['event' => 10100, 'date' => 'not a date'])->timestamp);
        self::assertNull(TrackingEvent::fromApi(['event' => 10100])->timestamp);
    }

    public function testHistorySkipsMalformed(): void
    {
        $events = TrackingEvent::history([['event' => 'x'], ['event' => 21700, 'name' => 'Прибуло', 'date' => '2026-11-09T10:00:00', 'step' => 2]]);

        self::assertCount(1, $events);
        self::assertSame(21700, $events[0]->code);
    }

    /**
     * Status-tracking віддає і `name`, і `eventName`; що з них місце, локальна документація не каже. Подія — eventName,
     * а `name` з індексом лишається місцем, щоб жодне поле не загубилось.
     */
    public function testEventNameIsTheEventAndNameWithIndexIsThePlace(): void
    {
        $event = TrackingEvent::fromApi(['event' => 21700, 'index' => '79007', 'name' => 'Львів 7', 'eventName' => 'Надійшло у відділення', 'date' => '2026-11-09T10:00:00']);

        self::assertSame('Надійшло у відділення', $event->name);
        self::assertSame('79007 Львів 7', $event->place);
    }

    public function testWithoutEventNameTheNameStaysTheEventAndIndexIsThePlace(): void
    {
        $event = TrackingEvent::fromApi(['event' => 21700, 'index' => '79007', 'name' => 'Прибуло', 'date' => '2026-11-09T10:00:00']);

        self::assertSame('Прибуло', $event->name);
        self::assertSame('79007', $event->place);
        self::assertSame('', TrackingEvent::fromApi(['event' => 21700, 'name' => 'Прибуло', 'index' => ['x']])->place);
    }
}
