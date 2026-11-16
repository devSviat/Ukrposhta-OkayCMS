<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/**
 * Історія подій відправлення в колонці `history`: JSON, у хронологічному порядку, лише найновіші MAX_EVENTS.
 * Читання перевіряє кожен запис: зіпсований JSON дає порожню історію, а не помилку картки.
 *
 * @phpstan-type Entry array{code: int, name: string, place: string, at: string, reason: ?int}
 */
final class ShipmentHistory
{
    /** Межа обрана для картки: вона показує всю збережену історію, а колонка не росте без кінця. */
    public const int MAX_EVENTS = 50;
    public const int TEXT_LENGTH = 255;

    /**
     * @param list<TrackingEvent> $events уже впорядковані TrackingEvent::history()
     * @return list<Entry>
     */
    public static function fromEvents(array $events): array
    {
        $history = [];
        foreach ($events as $event) {
            $entry = self::entry($event);
            if ($entry !== null) {
                $history[] = $entry;
            }
        }

        return array_slice($history, -self::MAX_EVENTS);
    }

    /**
     * Дописує подію тіку. Та сама подія приходить у кожному тіку, доки не з'явиться нова, тож код і дата її впізнають.
     *
     * @param list<Entry> $history
     * @return list<Entry>
     */
    public static function append(array $history, TrackingEvent $event): array
    {
        $entry = self::entry($event);
        if ($entry === null) {
            return $history;
        }
        foreach ($history as $known) {
            if ($known['code'] === $entry['code'] && $known['at'] === $entry['at']) {
                return $history;
            }
        }
        $history[] = $entry;
        usort($history, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_slice($history, -self::MAX_EVENTS);
    }

    /** @param list<Entry> $history */
    public static function encode(array $history): ?string
    {
        if ($history === []) {
            return null;
        }
        $json = json_encode(array_slice($history, -self::MAX_EVENTS), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /** @return list<Entry> */
    public static function decode(mixed $json): array
    {
        $items = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }
        $history = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !is_int($item['code'] ?? null)
                || !is_string($item['name'] ?? null)
                || !is_string($item['place'] ?? null)
                || !is_string($item['at'] ?? null)
                || \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $item['at']) === false) {
                continue;
            }
            $history[] = [
                'code' => $item['code'],
                'name' => mb_substr($item['name'], 0, self::TEXT_LENGTH),
                'place' => mb_substr($item['place'], 0, self::TEXT_LENGTH),
                'at' => $item['at'],
                'reason' => is_int($item['reason'] ?? null) ? $item['reason'] : null,
            ];
        }

        return array_slice($history, -self::MAX_EVENTS);
    }

    /** @return ?Entry */
    private static function entry(TrackingEvent $event): ?array
    {
        $at = $event->at();
        if ($event->code === null || $at === null) {
            return null;
        }

        return [
            'code' => $event->code,
            'name' => mb_substr($event->name, 0, self::TEXT_LENGTH),
            'place' => mb_substr($event->place, 0, self::TEXT_LENGTH),
            'at' => $at,
            'reason' => $event->reason,
        ];
    }
}
