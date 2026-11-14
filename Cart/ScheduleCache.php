<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;

/**
 * Розклад окремого пункту підтягується, коли пункт уперше показано: класифікатор віддає його лише
 * поштучно, а обхід усіх ~12k пунктів заради розкладів був би непотрібним навантаженням.
 */
final class ScheduleCache
{
    public const int TTL = 7 * 86400;
    public const int RETRY_AFTER = 900;
    private const string METHOD = 'get_postoffices_openhours_by_postindex';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly ClassifierClient $classifier,
        private readonly EntityFactory $entityFactory,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Розклад лише довідковий і змінюється рідко, тож прострочений кращий за жодного: збій API віддає старі
     * рядки й ставить позначку, щоб наступна спроба була не раніше ніж через RETRY_AFTER, а не на кожен показ пункту.
     *
     * @return ?list<array<string, string>> null, коли розкладу немає
     */
    public function for(object $office): ?array
    {
        $now = ($this->clock)();
        $stored = isset($office->schedule_json) ? (string)$office->schedule_json : null;
        if (self::isFresh($stored, $now) || self::isBackingOff($stored, $now)) {
            return self::rows($stored);
        }

        try {
            $rows = $this->classifier->rows(self::METHOD, ['pc' => (string)$office->postcode]);
        } catch (\RuntimeException) {
            $this->save($office, self::failureMarker($stored, $now));

            return self::rows($stored);
        }

        $this->save($office, self::envelope($rows, $now));

        return $rows;
    }

    public static function isFresh(?string $json, int $now): bool
    {
        $stored = self::decode($json);

        return $stored !== null && $now - (int)$stored['fetched_at'] < self::TTL;
    }

    private static function isBackingOff(?string $json, int $now): bool
    {
        $failedAt = (int)(self::decode($json)['failed_at'] ?? 0);

        return $failedAt > 0 && $now - $failedAt < self::RETRY_AFTER;
    }

    /** @return ?list<array<string, string>> */
    private static function rows(?string $json): ?array
    {
        $rows = self::decode($json)['rows'] ?? [];

        return $rows === [] ? null : $rows;
    }

    private static function failureMarker(?string $json, int $now): string
    {
        $stored = self::decode($json);

        return (string)json_encode(
            ['fetched_at' => (int)($stored['fetched_at'] ?? 0), 'rows' => $stored['rows'] ?? [], 'failed_at' => $now],
            JSON_UNESCAPED_UNICODE,
        );
    }

    private function save(object $office, string $json): void
    {
        $this->entityFactory->get(OfficeEntity::class)->update((int)$office->id, ['schedule_json' => $json]);
    }

    /** @return ?array{fetched_at:int, rows:list<array<string, string>>, failed_at?:int} */
    private static function decode(?string $json): ?array
    {
        $stored = $json === null ? null : json_decode($json, true);

        return is_array($stored) && isset($stored['fetched_at'], $stored['rows']) && is_array($stored['rows']) ? $stored : null;
    }

    /** @param list<array<string, string>> $rows */
    public static function envelope(array $rows, int $now): string
    {
        return (string)json_encode(['fetched_at' => $now, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    }
}
