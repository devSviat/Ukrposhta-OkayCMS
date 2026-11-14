<?php

namespace Modules\Sviat\Ukrposhta\Cart;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Cart\ScheduleCache;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;
use PHPUnit\Framework\TestCase;

class ScheduleCacheTest extends TestCase
{
    private const int NOW = 1_793_900_000;

    public function testFreshStoredScheduleIsUsedWithoutRequest(): void
    {
        $stored = json_encode(['fetched_at' => self::NOW - 3600, 'rows' => [['DAYOFWEEK_NUM' => '1']]]);

        self::assertTrue(ScheduleCache::isFresh($stored, self::NOW));
        self::assertFalse(ScheduleCache::isFresh(json_encode(['fetched_at' => self::NOW - 8 * 86400, 'rows' => []]), self::NOW));
        self::assertFalse(ScheduleCache::isFresh(null, self::NOW));
        self::assertFalse(ScheduleCache::isFresh('[]', self::NOW), 'a legacy shape without fetched_at is refetched');
    }

    public function testEnvelopeCarriesFetchTimeAndRows(): void
    {
        $rows = [['DAYOFWEEK_NUM' => '1', 'TFROM' => '08:00', 'TTO' => '20:00', 'INTERVALTYPE' => 'W']];

        self::assertSame(['fetched_at' => self::NOW, 'rows' => $rows], json_decode(ScheduleCache::envelope($rows, self::NOW), true));
    }

    private function cache(FakeTransport $transport, ?OfficeEntity $entity): ScheduleCache
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity ?? $this->createStub(OfficeEntity::class));
        $client = new ClassifierClient($transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-bbbb', false)));

        return new ScheduleCache($client, $factory, static fn(): int => self::NOW);
    }

    public function testStaleScheduleIsFetchedByPostcodeAndStored(): void
    {
        $row = ['DAYOFWEEK_NUM' => '1', 'TFROM' => '08:00', 'TTO' => '20:00', 'INTERVALTYPE' => 'W'];
        $transport = (new FakeTransport())->json(200, ['Entries' => ['Entry' => [$row]]]);
        $entity = $this->createMock(OfficeEntity::class);
        $entity->expects(self::once())->method('update')
            ->with(7, ['schedule_json' => json_encode(['fetched_at' => self::NOW, 'rows' => [$row]])]);

        $rows = $this->cache($transport, $entity)->for((object)['id' => 7, 'postcode' => '21001', 'schedule_json' => null]);

        self::assertSame([$row], $rows);
        self::assertStringContainsString('get_postoffices_openhours_by_postindex?pc=21001', $transport->requests()[0]->url);
    }

    public function testFreshScheduleMakesNoRequest(): void
    {
        $row = ['DAYOFWEEK_NUM' => '2'];
        $stored = json_encode(['fetched_at' => self::NOW - 100, 'rows' => [$row]]);
        $entity = $this->createMock(OfficeEntity::class);
        $entity->expects(self::never())->method('update');

        $rows = $this->cache(new FakeTransport(), $entity)->for((object)['id' => 7, 'postcode' => '21001', 'schedule_json' => $stored]);

        self::assertSame([$row], $rows);
    }

    public function testApiFailureReturnsExpiredRowsAndWritesBackoffMarker(): void
    {
        $row = ['DAYOFWEEK_NUM' => '1', 'TFROM' => '08:00', 'TTO' => '20:00', 'INTERVALTYPE' => 'W'];
        $old = json_encode(['fetched_at' => self::NOW - 8 * 86400, 'rows' => [$row]]);
        $transport = (new FakeTransport())->push(new TransportException('down'));
        $entity = $this->createMock(OfficeEntity::class);
        $entity->expects(self::once())->method('update')
            ->with(7, ['schedule_json' => json_encode(['fetched_at' => self::NOW - 8 * 86400, 'rows' => [$row], 'failed_at' => self::NOW])]);

        $rows = $this->cache($transport, $entity)->for((object)['id' => 7, 'postcode' => '21001', 'schedule_json' => $old]);

        self::assertSame([$row], $rows);
    }

    public function testSecondCallWithinBackoffDoesNotHitTheTransport(): void
    {
        $row = ['DAYOFWEEK_NUM' => '1'];
        $marker = json_encode(['fetched_at' => self::NOW - 8 * 86400, 'rows' => [$row], 'failed_at' => self::NOW - 60]);
        $entity = $this->createMock(OfficeEntity::class);
        $entity->expects(self::never())->method('update');
        $transport = new FakeTransport();

        $rows = $this->cache($transport, $entity)->for((object)['id' => 7, 'postcode' => '21001', 'schedule_json' => $marker]);

        self::assertSame([$row], $rows);
        self::assertSame([], $transport->requests());
    }

    public function testRetryAfterBackoffAndNoRowsAtAll(): void
    {
        $marker = json_encode(['fetched_at' => 0, 'rows' => [], 'failed_at' => self::NOW - ScheduleCache::RETRY_AFTER - 1]);
        $transport = (new FakeTransport())->push(new TransportException('down'));

        $rows = $this->cache($transport, $this->createStub(OfficeEntity::class))->for((object)['id' => 7, 'postcode' => '21001', 'schedule_json' => $marker]);

        self::assertNull($rows);
        self::assertCount(1, $transport->requests(), 'the backoff is over, so it tries again');
    }
}
