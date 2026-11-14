<?php

namespace Modules\Sviat\Ukrposhta\Cart;

use Okay\Modules\Sviat\Ukrposhta\Cart\WorkSchedule;
use PHPUnit\Framework\TestCase;

class WorkScheduleTest extends TestCase
{
    private static function row(int $day, string $from, string $to, string $type = 'W'): array
    {
        return ['DAYOFWEEK_NUM' => (string)$day, 'TFROM' => $from, 'TTO' => $to, 'INTERVALTYPE' => $type, 'WORKCOMMENT' => ''];
    }

    public function testGroupsEqualDays(): void
    {
        $rows = [];
        foreach ([1, 2, 3, 4, 5] as $day) {
            $rows[] = self::row($day, '08:00', '20:00');
        }
        $rows[] = self::row(6, '09:00', '15:00');

        self::assertSame([
            ['from' => 0, 'to' => 4, 'hours' => '08:00–20:00', 'break' => null],
            ['from' => 5, 'to' => 5, 'hours' => '09:00–15:00', 'break' => null],
            ['from' => 6, 'to' => 6, 'hours' => null, 'break' => null],
        ], WorkSchedule::groups(json_encode($rows)));
    }

    public function testBreakIntervalShownSeparately(): void
    {
        $rows = [];
        foreach ([1, 2, 3, 4, 5] as $day) {
            $rows[] = self::row($day, '09:00', '18:00');
            $rows[] = self::row($day, '13:00', '14:00', 'D');
        }

        $groups = WorkSchedule::groups(json_encode($rows));

        self::assertSame(['from' => 0, 'to' => 4, 'hours' => '09:00–18:00', 'break' => '13:00–14:00'], $groups[0]);
    }

    public function testStoredEnvelopeIsUnwrapped(): void
    {
        $json = json_encode(['fetched_at' => 1, 'rows' => [self::row(1, '08:00:00', '20:00:00')]]);

        self::assertSame('08:00–20:00', WorkSchedule::groups($json)[0]['hours']);
    }

    public function testNoScheduleOrGarbageIsNull(): void
    {
        self::assertNull(WorkSchedule::groups(''));
        self::assertNull(WorkSchedule::groups('[]'));
        self::assertNull(WorkSchedule::groups('{oops'));
        self::assertNull(WorkSchedule::groups(json_encode([self::row(9, '08:00', '20:00')])), 'day out of range');
    }

    public function testAllDay(): void
    {
        $rows = [];
        foreach (range(1, 7) as $day) {
            $rows[] = self::row($day, '00:00', '23:59');
        }

        self::assertSame([['allDay' => true]], WorkSchedule::groups(json_encode($rows)));
    }
}
