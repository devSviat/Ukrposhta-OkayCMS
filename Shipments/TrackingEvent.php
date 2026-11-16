<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Подія трекінгу Укрпошти. Поля, які не вдалося прочитати, лишаються null: такі події в історію не потрапляють. */
final readonly class TrackingEvent
{
    private const string TIMEZONE = 'Europe/Kyiv';

    public function __construct(
        public ?int $code,
        public string $name,
        public ?int $timestamp,
        public ?int $step,
        public ?int $reason,
        public string $barcode = '',
        public string $place = '',
    ) {
    }

    /**
     * Назва події — `eventName`, а `name` поруч із ним — місце разом з `index`. Без `eventName` подією лишається `name`:
     * локальна документація Status-tracking поля не описує, тож жодне з них не відкидається.
     *
     * @param array<string, mixed> $e
     */
    public static function fromApi(array $e): self
    {
        $eventName = self::text($e['eventName'] ?? null);
        $name = self::text($e['name'] ?? null);
        $place = implode(' ', array_filter([self::text($e['index'] ?? null), $eventName !== '' ? $name : ''], static fn(string $part): bool => $part !== ''));

        return new self(
            self::int($e['event'] ?? null),
            $eventName !== '' ? $eventName : $name,
            self::timestamp($e['date'] ?? null),
            self::int($e['step'] ?? null),
            self::int($e['eventReason_id'] ?? null),
            is_string($e['barcode'] ?? null) ? $e['barcode'] : '',
            $place,
        );
    }

    /**
     * Хронологічно за датою, а не за step: step у відповідях Укрпошти не йде за часом.
     *
     * @param list<mixed> $events
     * @return list<self> події без коду чи дати відкидаються
     */
    public static function history(array $events): array
    {
        $parsed = array_values(array_filter(
            array_map(static fn(mixed $e): self => self::fromApi(is_array($e) ? $e : []), $events),
            static fn(self $e): bool => $e->code !== null && $e->timestamp !== null,
        ));
        usort($parsed, static fn(self $a, self $b): int => [$a->timestamp, $a->step ?? 0] <=> [$b->timestamp, $b->step ?? 0]);

        return $parsed;
    }

    /** @param list<self> $history вже впорядкована history() */
    public static function latest(array $history): ?self
    {
        return $history === [] ? null : $history[array_key_last($history)];
    }

    public function at(): ?string
    {
        return $this->timestamp === null ? null : date('Y-m-d H:i:s', $this->timestamp);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string)$value) : '';
    }

    /** Укрпошта віддає event то числом, то рядком. */
    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : null;
    }

    /** Дата без часового поясу — за київським часом: так її пише сама Укрпошта. */
    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(self::TIMEZONE))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
