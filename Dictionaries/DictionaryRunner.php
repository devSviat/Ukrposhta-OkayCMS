<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\CredentialsProvider;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeRow;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\Ukrposhta\Storage\Mutex;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/**
 * Одне завдання планувальника виконує всю роботу з довідниками, кнопку в адмінці включно: кнопка лише
 * залишає запит, тож два запуски ніколи не перетинаються.
 */
final class DictionaryRunner
{
    private const string LOCK = 'dict';
    /** Дельт у класифікатора немає, кожен запуск — повний обхід країни, тож раз на тиждень. */
    private const int EVERY = 7 * 86400;
    /** Область, що лишилася зі старими рядками, повторюється наступного дня, а не щогодини: кожен запуск — повний обхід країни. */
    private const int RETRY_PARTIAL = 86400;
    private const int RETRY_AFTER_FAILURE = 3600;
    /** Тайм-аут завдання планувальника: запуск, що "в процесі" довше, було вбито. */
    public const int TASK_TIMEOUT = 3600;

    private readonly \Closure $now;

    public function __construct(
        private readonly RegionSweep $sweep,
        private readonly DictionaryStore $store,
        private readonly StateStorage $state,
        private readonly Mutex $mutex,
        private readonly CredentialsProvider $credentials,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    /** @return bool false — без bearer eCom запит не ставиться в чергу: такий запуск однаково нічого не завантажить */
    public function requestRun(): bool
    {
        if (!$this->hasBearer()) {
            return false;
        }
        $status = DictionaryStatus::load($this->state);
        $status->requestedAt = ($this->now)();
        $status->save($this->state);

        return true;
    }

    public function hasBearer(): bool
    {
        return $this->credentials->get()->ecomBearer !== '';
    }

    /** Викликається планувальником кожні 5 хвилин; працює, лише коли щось пора виконати. */
    public function tick(): void
    {
        // Перевірка runningSince і його запис — два кроки: два процеси, що стартували разом, пройшли б обидва.
        if (!$this->mutex->acquire(self::LOCK)) {
            return;
        }
        try {
            $this->tickLocked();
        } finally {
            $this->mutex->release(self::LOCK);
        }
    }

    private function tickLocked(): void
    {
        $now = ($this->now)();
        $status = DictionaryStatus::load($this->state);
        if ($status->runningSince !== null) {
            // Сток 4.5.3 ігнорує результат блокування планувальника, тож запуск може ще тривати.
            if ($now - $status->runningSince < self::TASK_TIMEOUT) {
                return;
            }
            // Убитий (тайм-аут, брак пам'яті): жоден `finally` не виконався, записуємо це як збій, щоб
            // повтор чекав годину, а не стартував кожні 5 хвилин.
            $status->lastRun = self::result($status->runningSince, $status->runningSince + self::TASK_TIMEOUT, DictionaryStatus::FAILED, 'interrupted');
            $status->runningSince = null;
            $status->save($this->state);
        }
        if (!$this->isDue($status, $now)) {
            return;
        }

        $status->requestedAt = null;
        if (!$this->hasBearer()) {
            $status->lastRun = self::result($now, $now, DictionaryStatus::NO_KEYS, 'eCom bearer is not set');
            $status->save($this->state);

            return;
        }
        $status->runningSince = $now;
        $status->save($this->state);

        $run = self::result($now, $now, DictionaryStatus::FAILED, null);
        try {
            $this->sweepRegions($now, $run);
            $run['status'] = $run['skipped'] === [] && $run['unresolved'] === [] ? DictionaryStatus::OK : DictionaryStatus::PARTIAL;
        } catch (\Throwable $e) {
            $run['error'] = $e->getMessage();
        } finally {
            $run['finished'] = ($this->now)();
            $status->lastRun = $run;
            $status->runningSince = null;
            // Натискання кнопки під час запуску замовляє ще один.
            $status->requestedAt = DictionaryStatus::load($this->state)->requestedAt;
            $status->save($this->state);
        }
    }

    private function isDue(DictionaryStatus $status, int $now): bool
    {
        if ($status->requestedAt !== null || $status->lastRun === null) {
            return true;
        }
        $wait = match ($status->lastRun['status']) {
            DictionaryStatus::OK => self::EVERY,
            DictionaryStatus::PARTIAL => self::RETRY_PARTIAL,
            default => self::RETRY_AFTER_FAILURE,
        };

        return $now - (int)$status->lastRun['finished'] >= $wait;
    }

    private function sweepRegions(int $run, array &$result): void
    {
        $offices = new UpdateCounts();
        $cities = new UpdateCounts();
        $regions = $this->sweep->regions();
        $result['unresolved'] = $this->sweep->unresolved();
        foreach ($regions as $region) {
            try {
                [$regionOffices, $regionCities] = $this->region($region['id'], $run);
            } catch (ImplausibleRegion $e) {
                $result['skipped'][$region['name']] = $e->getMessage();
                continue;
            } catch (UkrposhtaApiException $e) {
                // Невірний bearer зламає кожну область: зупиняємо запуск, а не робимо 25 однакових помилок.
                if (in_array($e->kind, [ApiErrorKind::BadBearer, ApiErrorKind::NotConfigured], true)) {
                    throw $e;
                }
                $result['skipped'][$region['name']] = $e->getMessage();
                continue;
            }
            $offices = $offices->plus($regionOffices);
            $cities = $cities->plus($regionCities);
        }
        $result['counts'] = [DictionaryStore::OFFICES => $offices->toArray(), DictionaryStore::CITIES => $cities->toArray()];
    }

    /** @return array{0: UpdateCounts, 1: UpdateCounts} пункти, міста */
    private function region(int $regionId, int $run): array
    {
        $offices = [];
        $cityEntries = [];
        $points = [];
        foreach ($this->sweep->offices($regionId) as $entry) {
            // Область рядка — та, яку обходили: лише так рядок потрапить під заміну саме цієї області.
            $entry['REGION_ID'] = (string)$regionId;
            $office = OfficeRow::fromClassifier($entry);
            if ($office === null) {
                continue;
            }
            $offices[$office['id']] = $office;
            if ($office['city_id'] > 0) {
                $cityEntries[$office['city_id']] = $entry;
                $points[$office['city_id']] = ($points[$office['city_id']] ?? 0) + 1;
            }
        }
        $cities = [];
        foreach ($cityEntries as $cityId => $entry) {
            $city = CityRow::fromOffice($entry, $points[$cityId]);
            if ($city['id'] > 0) {
                $cities[$city['id']] = $city;
            }
        }

        $officeLoader = new TableLoader($this->store, DictionaryStore::OFFICES, $regionId, $run);
        $cityLoader = new TableLoader($this->store, DictionaryStore::CITIES, $regionId, $run);
        // Обидві перевірки — до першого запису: область не повинна лишитися з новими пунктами й порожніми містами,
        // як буває, коли класифікатор перейменує поле id міста.
        TableLoader::assertPlausibleRegion($officeLoader->storedAtStart(), count($offices));
        TableLoader::assertPlausibleRegion($cityLoader->storedAtStart(), count($cities));
        foreach ($offices as $office) {
            $officeLoader->put($office);
        }
        foreach ($cities as $city) {
            $cityLoader->put($city);
        }

        return [$officeLoader->finish(), $cityLoader->finish()];
    }

    /** @return array{started:int, finished:int, status:string, counts:array, skipped:array<string, string>, unresolved:list<string>, error:?string} */
    private static function result(int $started, int $finished, string $status, ?string $error): array
    {
        return ['started' => $started, 'finished' => $finished, 'status' => $status, 'counts' => [], 'skipped' => [], 'unresolved' => [], 'error' => $error];
    }
}
