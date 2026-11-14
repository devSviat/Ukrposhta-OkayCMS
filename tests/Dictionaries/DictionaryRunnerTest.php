<?php

namespace Modules\Sviat\Ukrposhta\Dictionaries;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/InMemoryDictionaryStore.php';
require_once __DIR__ . '/../Support/ClassifierFixtures.php';

use Modules\Sviat\Ukrposhta\Support\ClassifierFixtures;
use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryDictionaryStore;
use Modules\Sviat\Ukrposhta\Support\InMemoryMutex;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;
use PHPUnit\Framework\TestCase;

class DictionaryRunnerTest extends TestCase
{
    private const int HOUR = 3600;
    private const int DAY = 86400;
    private const int T0 = 1_793_900_000;
    private const int VINNYTSIA = 270;
    private const int VOLYN = 271;

    private FakeTransport $transport;
    private InMemoryState $state;
    private InMemoryMutex $mutex;
    private InMemoryDictionaryStore $store;
    private int $now = self::T0;
    private string $bearer = 'ecom';

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
        $this->mutex = new InMemoryMutex();
        $this->store = new InMemoryDictionaryStore();
        $this->state->set('region_ids', json_encode(['Вінницька' => self::VINNYTSIA, 'Волинська' => self::VOLYN], JSON_UNESCAPED_UNICODE));
    }

    /** @param list<string> $regions */
    private function runner(array $regions = ['Вінницька']): DictionaryRunner
    {
        $credentials = new FixedCredentials(new Credentials($this->bearer, 'trk', 'cp', false));
        $sweep = new RegionSweep(new ClassifierClient($this->transport, $credentials), $this->state, $regions);

        return new DictionaryRunner($sweep, $this->store, $this->state, $this->mutex, $credentials, fn(): int => $this->now);
    }

    private function dictStatus(): DictionaryStatus
    {
        return DictionaryStatus::load($this->state);
    }

    private function seedOffices(int $regionId, int $count): void
    {
        for ($id = 1; $id <= $count; $id++) {
            $this->store->tables[DictionaryStore::OFFICES][$regionId * 1000 + $id] = [
                'id' => $regionId * 1000 + $id, 'region_id' => $regionId, 'row_hash' => 'old', 'seen_run' => 1,
            ];
        }
    }

    private function answerOffices(int $count, int $firstId = 1): void
    {
        $this->transport->json(200, ClassifierFixtures::answer(ClassifierFixtures::offices($firstId, $count)));
    }

    public function testEmptyRegionAnswerKeepsOldRows(): void
    {
        $this->seedOffices(self::VINNYTSIA, 120);
        $this->transport->json(200, ['Entries' => []]);

        $this->runner()->tick();

        $this->assertCount(120, $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA));
        $this->assertSame('partial', $this->dictStatus()->lastRun['status']);
        $this->assertArrayHasKey('Вінницька', $this->dictStatus()->lastRun['skipped']);
    }

    public function testHalvedRegionKeepsOldRows(): void
    {
        $this->seedOffices(self::VINNYTSIA, 120);
        $this->answerOffices(59);

        $this->runner()->tick();

        $offices = $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA);
        $this->assertCount(120, $offices);
        $this->assertSame(['old'], array_values(array_unique(array_column($offices, 'row_hash'))));
        $this->assertSame('partial', $this->dictStatus()->lastRun['status']);
    }

    public function testHalfOfStoredRowsReplacesRegion(): void
    {
        $this->seedOffices(self::VINNYTSIA, 120);
        $this->answerOffices(60);

        $this->runner()->tick();

        $this->assertCount(60, $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA));
        $this->assertSame('ok', $this->dictStatus()->lastRun['status']);
        $this->assertSame(['added' => 60, 'changed' => 0, 'deleted' => 120, 'unchanged' => 0], $this->dictStatus()->lastRun['counts']['offices']);
    }

    public function testImplausibleRegionDoesNotStopOtherRegions(): void
    {
        $this->seedOffices(self::VINNYTSIA, 120);
        $this->seedOffices(self::VOLYN, 10);
        $this->transport->json(200, ['Entries' => []]);
        $this->answerOffices(8, 500);

        $this->runner(['Вінницька', 'Волинська'])->tick();

        $this->assertCount(120, $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA));
        $volyn = $this->store->region(DictionaryStore::OFFICES, self::VOLYN);
        $this->assertSame(range(500, 507), array_keys($volyn));
        $this->assertSame(['Вінницька'], array_keys($this->dictStatus()->lastRun['skipped']));
    }

    public function testRegionFailureKeepsItsRowsAndOthersAreLoaded(): void
    {
        $this->seedOffices(self::VINNYTSIA, 5);
        $this->transport->json(503, []);
        $this->answerOffices(3, 500);

        $this->runner(['Вінницька', 'Волинська'])->tick();

        $this->assertCount(5, $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA));
        $this->assertCount(3, $this->store->region(DictionaryStore::OFFICES, self::VOLYN));
        $this->assertSame('partial', $this->dictStatus()->lastRun['status']);
    }

    public function testUnresolvedRegionMakesRunPartial(): void
    {
        $this->transport->json(200, ClassifierFixtures::answer([]));
        $this->answerOffices(3);

        $this->runner(['Луганська', 'Вінницька'])->tick();

        $this->assertSame('partial', $this->dictStatus()->lastRun['status']);
        $this->assertSame(['Луганська'], $this->dictStatus()->lastRun['unresolved']);
        $this->assertCount(3, $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA));
    }

    public function testMissingCityIdsKeepBothTables(): void
    {
        $this->seedOffices(self::VINNYTSIA, 4);
        $this->store->tables[DictionaryStore::CITIES][100] = ['id' => 100, 'region_id' => self::VINNYTSIA, 'row_hash' => 'old', 'seen_run' => 1];
        $entries = ClassifierFixtures::offices(1, 4);
        foreach ($entries as &$entry) {
            unset($entry['CITY_ID']);
        }
        unset($entry);
        $this->transport->json(200, ClassifierFixtures::answer($entries));

        $this->runner()->tick();

        $offices = $this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA);
        $this->assertSame(range(270_001, 270_004), array_keys($offices));
        $this->assertSame(['old'], array_values(array_unique(array_column($offices, 'row_hash'))));
        $this->assertSame([100], array_keys($this->store->region(DictionaryStore::CITIES, self::VINNYTSIA)));
        $this->assertSame(['Вінницька'], array_keys($this->dictStatus()->lastRun['skipped']));
        $this->assertSame('partial', $this->dictStatus()->lastRun['status']);
    }

    public function testRegionThatNeverHadPointsIsNotSkipped(): void
    {
        $this->transport->json(200, ['Entries' => []]);

        $this->runner()->tick();

        $this->assertSame('ok', $this->dictStatus()->lastRun['status']);
        $this->assertSame([], $this->dictStatus()->lastRun['skipped']);
    }

    public function testSecondTickWhileRunningDoesNothing(): void
    {
        $this->mutex->held['dict'] = true;

        $this->runner()->tick();

        $this->assertSame([], $this->transport->requests());
        $this->assertNull($this->dictStatus()->lastRun);

        unset($this->mutex->held['dict']);
        $status = $this->dictStatus();
        $status->runningSince = $this->now - 60;
        $status->save($this->state);

        $this->runner()->tick();

        $this->assertSame([], $this->transport->requests());
        $this->assertNull($this->dictStatus()->lastRun);
    }

    public function testRequestRunTriggersOnNextTick(): void
    {
        $this->answerOffices(3);
        $this->runner()->tick();
        $this->assertSame('ok', $this->dictStatus()->lastRun['status']);

        $this->now += self::HOUR;
        $this->runner()->tick();
        $this->assertCount(1, $this->transport->requests());

        $this->runner()->requestRun();
        $this->answerOffices(3);
        $this->runner()->tick();
        $this->assertCount(2, $this->transport->requests());
        $this->assertNull($this->dictStatus()->requestedAt);
        $this->assertSame(['added' => 0, 'changed' => 0, 'deleted' => 0, 'unchanged' => 3], $this->dictStatus()->lastRun['counts']['offices']);
    }

    public function testRunsAgainAfterSevenDays(): void
    {
        $this->answerOffices(3);
        $this->runner()->tick();

        $this->now += 7 * self::DAY - 1;
        $this->runner()->tick();
        $this->assertCount(1, $this->transport->requests());

        $this->now += 1;
        $this->answerOffices(3);
        $this->runner()->tick();
        $this->assertCount(2, $this->transport->requests());
    }

    public function testFailureRetriesAfterAnHour(): void
    {
        $this->transport->json(403, []);
        $this->runner()->tick();
        $this->assertSame('failed', $this->dictStatus()->lastRun['status']);

        $this->now += self::HOUR - 1;
        $this->runner()->tick();
        $this->assertCount(1, $this->transport->requests());

        $this->now += 1;
        $this->answerOffices(3);
        $this->runner()->tick();
        $this->assertSame('ok', $this->dictStatus()->lastRun['status']);
    }

    public function testWithoutBearerClassifierIsNotCalled(): void
    {
        $this->bearer = '';

        $this->runner()->tick();

        $this->assertSame([], $this->transport->requests());
        $this->assertSame('no_keys', $this->dictStatus()->lastRun['status']);
    }

    public function testRequestWithoutBearerIsRefusedAndNotQueued(): void
    {
        $this->bearer = '';
        $before = $this->state->values;

        self::assertFalse($this->runner()->requestRun());

        self::assertNull($this->dictStatus()->requestedAt);
        self::assertSame($before, $this->state->values, 'nothing written to module state');
    }

    public function testRequestWithBearerIsQueued(): void
    {
        self::assertTrue($this->runner()->requestRun());
        self::assertSame(self::T0, $this->dictStatus()->requestedAt);
    }

    public function testCitiesOnlyWherePointsExist(): void
    {
        $this->transport->json(200, ClassifierFixtures::answer([
            ClassifierFixtures::office(1, ['CITY_ID' => '100', 'CITY_UA' => 'Вінниця']),
            ClassifierFixtures::office(2, ['CITY_ID' => '100', 'CITY_UA' => 'Вінниця', 'TYPE_ACRONYM' => 'П-т']),
            ClassifierFixtures::office(3, ['CITY_ID' => '200', 'CITY_UA' => 'Гнівань', 'TYPE_ACRONYM' => 'ПВ']),
            ClassifierFixtures::office(4, ['CITY_ID' => '300', 'CITY_UA' => 'Іллінці', 'LOCK_CODE' => '1']),
        ]));

        $this->runner()->tick();

        $cities = $this->store->region(DictionaryStore::CITIES, self::VINNYTSIA);
        $this->assertSame([100], array_keys($cities));
        $this->assertSame([1, 2], array_keys($this->store->region(DictionaryStore::OFFICES, self::VINNYTSIA)));
    }
}
