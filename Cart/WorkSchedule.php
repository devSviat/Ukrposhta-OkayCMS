<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

/**
 * Тиждень пункту так, як його показує кошик: дні з однаковими годинами в одній групі ("Пн–Пт 08:00–20:00").
 * День тижня, а не "сьогодні": посилку забирають через кілька днів після оформлення замовлення.
 */
final class WorkSchedule
{
    private const string WORK = 'W';
    private const string BREAK = 'D';

    /**
     * @param string $json рядки `get_postoffices_openhours_by_postindex` або конверт ScheduleCache {fetched_at, rows}
     * @return list<array{from:int, to:int, hours:?string, break:?string}>|list<array{allDay:true}>|null дні 0 = понеділок … 6 = неділя;
     *         hours null — вихідний; null, коли розкладу немає або його не вдається прочитати
     */
    public static function groups(string $json): ?array
    {
        $rows = json_decode($json, true);
        if (is_array($rows) && isset($rows['rows'])) {
            $rows = $rows['rows'];
        }
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $work = array_fill(0, 7, []);
        $breaks = array_fill(0, 7, []);
        $valid = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $day = (int)($row['DAYOFWEEK_NUM'] ?? 0) - 1;
            $from = self::time((string)($row['TFROM'] ?? ''));
            $to = self::time((string)($row['TTO'] ?? ''));
            if ($day < 0 || $day > 6 || $from === null || $to === null) {
                continue;
            }
            $type = strtoupper((string)($row['INTERVALTYPE'] ?? self::WORK));
            if ($type === self::WORK) {
                $work[$day][] = [$from, $to];
                $valid = true;
            } elseif ($type === self::BREAK) {
                $breaks[$day][] = [$from, $to];
            }
        }
        if (!$valid) {
            return null;
        }

        $allDay = array_filter($work, static fn(array $day): bool => count($day) === 1 && $day[0][0] <= '00:01' && $day[0][1] >= '23:59');
        if (count($allDay) === 7) {
            return [['allDay' => true]];
        }

        $groups = [];
        foreach ($work as $day => $intervals) {
            $hours = self::join($intervals);
            $break = $hours === null ? null : self::join($breaks[$day]);
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['hours'] === $hours && $groups[$last]['break'] === $break) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'hours' => $hours, 'break' => $break];
            }
        }

        return $groups;
    }

    /** @param list<array{0:string,1:string}> $intervals */
    private static function join(array $intervals): ?string
    {
        if ($intervals === []) {
            return null;
        }
        sort($intervals);

        return implode(', ', array_map(static fn(array $i): string => $i[0] . '–' . $i[1], $intervals));
    }

    private static function time(string $value): ?string
    {
        return preg_match('/^([01]?\d|2[0-4]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $m) === 1 ? sprintf('%02d:%s', (int)$m[1], $m[2]) : null;
    }
}
