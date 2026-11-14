<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;

/** Дані шаблону для блоку довідників. */
final class DictionaryStatusView
{
    /** Завдання планувальника запускається кожні 5 хвилин; запит, старший за це, означає, що воно не працює. */
    private const int SCHEDULER_SILENT_AFTER = 15 * 60;
    /** Оновлення щотижневе, повтор частково оновлених областей — щодня; 10 днів без запуску означають, що планувальник зупинився. */
    private const int STALE_AFTER = 10 * 86400;

    /**
     * @param array{office?:int, locker?:int, pudo?:int, cities?:int} $counts
     * @param bool $hasBearer чи збережено bearer eCom: без нього запуск неможливий, тож кнопки й черги немає
     * @return array<string, mixed>
     */
    public static function vars(DictionaryStatus $status, int $now, array $counts, bool $hasBearer, ?\DateTimeZone $timezone = null): array
    {
        $state = self::state($status, $now, $hasBearer);
        $busy = $state === 'queued' || $state === 'running';
        $run = $status->lastRun;
        $changes = $run['counts'][DictionaryStore::OFFICES] ?? [];
        $figures = [
            'office' => (int)($counts['office'] ?? 0),
            'locker' => (int)($counts['locker'] ?? 0),
            'pudo' => (int)($counts['pudo'] ?? 0),
            'cities' => (int)($counts['cities'] ?? 0),
        ];
        $loaded = in_array($run['status'] ?? null, [DictionaryStatus::OK, DictionaryStatus::PARTIAL], true);
        $skipped = [];
        foreach (is_array($run['skipped'] ?? null) ? $run['skipped'] : [] as $region => $reason) {
            $skipped[] = ['region' => (string)$region, 'reason' => (string)$reason];
        }

        return [
            'state' => $state,
            'tone' => match ($state) {
                'error' => 'error',
                'scheduler_idle', 'stopped', 'stale', 'partial', 'no_keys' => 'warning',
                default => 'info',
            },
            'busy' => $busy,
            'can_run' => $hasBearer && !$busy,
            'has_bearer' => $hasBearer,
            'has_data' => $figures['office'] + $figures['locker'] + $figures['pudo'] > 0,
            // Розряди розділяються нерозривним пробілом, як пишуть великі числа українською.
            'shown' => array_map(static fn(int $n): string => number_format($n, 0, '', "\u{00A0}"), $figures),
            'updated_at' => $loaded && isset($run['finished'])
                ? new \DateTimeImmutable('@' . (int)$run['finished'])
                    ->setTimezone($timezone ?? new \DateTimeZone(date_default_timezone_get()))
                    ->format('d.m.Y H:i')
                : null,
            'changes' => [
                'added' => (int)($changes['added'] ?? 0),
                'changed' => (int)($changes['changed'] ?? 0),
                'deleted' => (int)($changes['deleted'] ?? 0),
            ],
            'error' => $state === 'error' ? (string)($run['error'] ?? '') : null,
            'skipped' => $loaded ? $skipped : [],
            'unresolved' => $loaded && is_array($run['unresolved'] ?? null) ? array_map(strval(...), $run['unresolved']) : [],
        ];
    }

    private static function state(DictionaryStatus $status, int $now, bool $hasBearer): string
    {
        if ($status->runningSince !== null) {
            return $now - $status->runningSince < DictionaryRunner::TASK_TIMEOUT ? 'running' : 'stopped';
        }
        // Запит у черзі без ключа планувальник лише закриє як no_keys; «в черзі» тут було б неправдою.
        if (!$hasBearer) {
            return 'no_keys';
        }
        if ($status->requestedAt !== null) {
            return $now - $status->requestedAt < self::SCHEDULER_SILENT_AFTER ? 'queued' : 'scheduler_idle';
        }
        $run = $status->lastRun;

        return match (true) {
            $run === null => 'none',
            ($run['error'] ?? null) === 'interrupted' => 'stopped',
            $run['status'] === DictionaryStatus::NO_KEYS => 'no_keys',
            !in_array($run['status'], [DictionaryStatus::OK, DictionaryStatus::PARTIAL], true) => 'error',
            $now - $run['finished'] > self::STALE_AFTER => 'stale',
            $run['status'] === DictionaryStatus::PARTIAL => 'partial',
            default => 'ok',
        };
    }
}
