<?php

namespace Modules\Sviat\Ukrposhta\Dictionaries;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/ClassifierFixtures.php';

use Modules\Sviat\Ukrposhta\Support\ClassifierFixtures;
use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\RegionSweep;
use PHPUnit\Framework\TestCase;

class RegionSweepTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryState $state;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
    }

    private function sweep(array $names): RegionSweep
    {
        $client = new ClassifierClient($this->transport, new FixedCredentials(new Credentials('ecom', 'trk', 'cp', false)));

        return new RegionSweep($client, $this->state, $names);
    }

    public function testHoldsTwentyFiveRegionsWithoutCrimea(): void
    {
        $this->assertCount(25, RegionSweep::REGIONS);
        $this->assertContains('м. Київ', RegionSweep::REGIONS);
        $this->assertSame([], preg_grep('/Крим|Севастополь/u', RegionSweep::REGIONS));
    }

    public function testRegionIdsResolvedAndCached(): void
    {
        $this->transport->json(200, ClassifierFixtures::region(270, 'Вінницька'));
        // Частковий пошук за «Київ» повертає й область, і місто.
        $this->transport->json(200, ClassifierFixtures::answer([
            ['REGION_ID' => '280', 'REGION_UA' => 'Київська'],
            ['REGION_ID' => '286', 'REGION_UA' => 'Київ'],
        ]));

        $regions = $this->sweep(['Вінницька', 'м. Київ'])->regions();

        $expected = [['id' => 270, 'name' => 'Вінницька'], ['id' => 286, 'name' => 'м. Київ']];
        $this->assertSame($expected, $regions);
        $this->assertStringContainsString('region_name=' . rawurlencode('Київ'), $this->transport->requests()[1]->url);

        $this->assertSame($expected, $this->sweep(['Вінницька', 'м. Київ'])->regions());
        $this->assertCount(2, $this->transport->requests());
    }

    public function testUnresolvedRegionReported(): void
    {
        $this->transport->json(200, ClassifierFixtures::region(270, 'Вінницька'));
        $this->transport->json(200, ClassifierFixtures::answer([]));
        $sweep = $this->sweep(['Вінницька', 'Луганська']);

        $this->assertSame([['id' => 270, 'name' => 'Вінницька']], $sweep->regions());
        $this->assertSame(['Луганська'], $sweep->unresolved());

        // Нерозпізнана назва не кешується як відсутня: наступний запуск питає ще раз.
        $this->transport->json(200, ClassifierFixtures::region(290, 'Луганська'));
        $again = $this->sweep(['Вінницька', 'Луганська']);
        $this->assertSame([['id' => 270, 'name' => 'Вінницька'], ['id' => 290, 'name' => 'Луганська']], $again->regions());
        $this->assertSame([], $again->unresolved());
    }

    public function testFailedNameDoesNotLoseResolvedIds(): void
    {
        $this->transport->json(200, ClassifierFixtures::region(270, 'Вінницька'));
        $this->transport->json(503, []);
        $this->transport->json(200, ClassifierFixtures::region(271, 'Волинська'));
        $sweep = $this->sweep(['Вінницька', 'Луганська', 'Волинська']);

        $this->assertSame([['id' => 270, 'name' => 'Вінницька'], ['id' => 271, 'name' => 'Волинська']], $sweep->regions());
        $this->assertSame(['Луганська'], $sweep->unresolved());
        $this->assertSame(['Вінницька' => 270, 'Волинська' => 271], json_decode($this->state->get('region_ids'), true));
    }

    public function testBadBearerStopsButKeepsResolvedIds(): void
    {
        $this->transport->json(200, ClassifierFixtures::region(270, 'Вінницька'));
        $this->transport->json(403, []);

        try {
            $this->sweep(['Вінницька', 'Волинська'])->regions();
            $this->fail('BadBearer must stop the sweep');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::BadBearer, $e->kind);
        }
        $this->assertSame(['Вінницька' => 270], json_decode($this->state->get('region_ids'), true));
    }

    public function testOfficesAskByRegionId(): void
    {
        $this->transport->json(200, ClassifierFixtures::answer(ClassifierFixtures::offices(1, 2)));

        $this->assertCount(2, $this->sweep([])->offices(270));
        $url = $this->transport->requests()[0]->url;
        $this->assertStringContainsString('/get_postoffices_by_city_id?', $url);
        $this->assertStringContainsString('region_id=270', $url);
    }
}
