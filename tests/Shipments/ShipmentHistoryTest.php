<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentHistory;
use Okay\Modules\Sviat\Ukrposhta\Shipments\TrackingEvent;
use PHPUnit\Framework\TestCase;

class ShipmentHistoryTest extends TestCase
{
    private static function event(int $code, string $date, string $name = 'Подія', string $place = ''): TrackingEvent
    {
        return TrackingEvent::fromApi(['event' => $code, 'eventName' => $name, 'index' => $place, 'date' => $date]);
    }

    /** Подія без поясу — київський час, а `at` пишеться в поясі PHP, як і колонка event_at. */
    private static function at(string $kyiv): string
    {
        return date('Y-m-d H:i:s', new \DateTimeImmutable($kyiv, new \DateTimeZone('Europe/Kyiv'))->getTimestamp());
    }

    public function testFromEventsKeepsTheNewestFifty(): void
    {
        $events = [];
        for ($i = 0; $i < 60; $i++) {
            $events[] = self::event(20700, date('Y-m-d\TH:i:s', strtotime('2026-11-01 00:00:00') + $i * 60));
        }

        $history = ShipmentHistory::decode(ShipmentHistory::encode(ShipmentHistory::fromEvents($events)));

        self::assertCount(ShipmentHistory::MAX_EVENTS, $history);
        self::assertSame(self::at('2026-11-01 00:10:00'), $history[0]['at'], 'the ten oldest are dropped');
        self::assertSame(self::at('2026-11-01 00:59:00'), $history[49]['at']);
    }

    public function testEntryKeepsCodeNamePlaceDateAndReason(): void
    {
        $event = TrackingEvent::fromApi(['event' => '41000', 'eventName' => 'Вручено', 'name' => 'Львів 7', 'index' => '79007', 'date' => '2026-11-10T15:00:00', 'eventReason_id' => 10]);

        self::assertSame([['code' => 41000, 'name' => 'Вручено', 'place' => '79007 Львів 7', 'at' => self::at('2026-11-10 15:00:00'), 'reason' => 10]], ShipmentHistory::fromEvents([$event]));
    }

    public function testAppendSkipsTheSameCodeAndDate(): void
    {
        $history = ShipmentHistory::fromEvents([self::event(10100, '2026-11-08T09:00:00'), self::event(20700, '2026-11-08T12:00:00')]);

        $same = ShipmentHistory::append($history, self::event(20700, '2026-11-08T12:00:00', 'Інша назва'));
        $sameCodeLater = ShipmentHistory::append($history, self::event(20700, '2026-11-09T07:00:00'));

        self::assertSame($history, $same);
        self::assertCount(3, $sameCodeLater);
        self::assertSame(self::at('2026-11-09 07:00:00'), $sameCodeLater[2]['at']);
    }

    public function testAppendKeepsDateOrderAndTheLimit(): void
    {
        $history = [];
        for ($i = 0; $i < ShipmentHistory::MAX_EVENTS; $i++) {
            $history = ShipmentHistory::append($history, self::event(20700 + $i, date('Y-m-d\TH:i:s', strtotime('2026-11-01 00:00:00') + $i * 60)));
        }
        $history = ShipmentHistory::append($history, self::event(21700, '2026-11-02T00:00:00'));
        $late = ShipmentHistory::append([], self::event(21700, '2026-11-02T00:00:00'));
        $late = ShipmentHistory::append($late, self::event(10100, '2026-11-01T00:00:00'));

        self::assertCount(ShipmentHistory::MAX_EVENTS, $history);
        self::assertSame(21700, $history[ShipmentHistory::MAX_EVENTS - 1]['code']);
        self::assertSame(20701, $history[0]['code'], 'the oldest one goes');
        self::assertSame([10100, 21700], array_column($late, 'code'), 'an older event lands before the newer one');
    }

    public function testAppendIgnoresAnEventWithoutCodeOrDate(): void
    {
        self::assertSame([], ShipmentHistory::append([], TrackingEvent::fromApi(['event' => 'x', 'date' => '2026-11-01T00:00:00'])));
        self::assertSame([], ShipmentHistory::append([], TrackingEvent::fromApi(['event' => 10100])));
    }

    public function testDecodeDropsWhatIsNotAnEntry(): void
    {
        self::assertSame([], ShipmentHistory::decode(null));
        self::assertSame([], ShipmentHistory::decode('{not json'));
        self::assertSame([], ShipmentHistory::decode('{"code": 1}'));
        $decoded = ShipmentHistory::decode(json_encode([
            ['code' => 10100, 'name' => 'Прийнято', 'place' => '01001', 'at' => '2026-11-08 09:00:00', 'reason' => null],
            ['code' => 'x', 'name' => 'Прийнято', 'place' => '', 'at' => '2026-11-08 09:00:00'],
            ['code' => 10100, 'name' => ['x'], 'place' => '', 'at' => '2026-11-08 09:00:00'],
            ['code' => 10100, 'name' => 'Прийнято', 'place' => '', 'at' => 'вчора'],
            'рядок',
        ]));

        self::assertSame([['code' => 10100, 'name' => 'Прийнято', 'place' => '01001', 'at' => '2026-11-08 09:00:00', 'reason' => null]], $decoded);
    }

    public function testLongTextIsCutBeforeSaving(): void
    {
        $history = ShipmentHistory::fromEvents([self::event(21700, '2026-11-09T10:00:00', str_repeat('Ж', 400), str_repeat('П', 400))]);
        $decoded = ShipmentHistory::decode(ShipmentHistory::encode($history));

        self::assertSame(ShipmentHistory::TEXT_LENGTH, mb_strlen($decoded[0]['name']));
        self::assertSame(ShipmentHistory::TEXT_LENGTH, mb_strlen($decoded[0]['place']));
        self::assertNull(ShipmentHistory::encode([]));
    }
}
