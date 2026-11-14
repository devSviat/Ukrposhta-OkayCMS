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
    ) {
    }

    /** @param array<string, mixed> $e */
    public static function fromApi(array $e): self
    {
        $name = $e['name'] ?? $e['eventName'] ?? '';

        return new self(
            self::int($e['event'] ?? null),
            is_string($name) ? $name : '',
            self::timestamp($e['date'] ?? null),
            self::int($e['step'] ?? null),
            self::int($e['eventReason_id'] ?? null),
            is_string($e['barcode'] ?? null) ? $e['barcode'] : '',
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
