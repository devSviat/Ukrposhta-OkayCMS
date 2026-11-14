<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryShipmentRows.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\Ukrposhta\Support\ArrayLogger;
use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryMutex;
use Modules\Sviat\Ukrposhta\Support\InMemoryShipmentRows;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentTracking;
use PHPUnit\Framework\TestCase;

class ShipmentTrackingTest extends TestCase
{
    private const int NOW = 1_900_000_000;

    private FakeTransport $transport;
    private InMemoryShipmentRows $rows;
    private InMemoryMutex $mutex;
    private InMemoryState $state;
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryShipmentRows();
        $this->rows->now = self::NOW;
        $this->mutex = new InMemoryMutex();
        $this->state = new InMemoryState();
        $this->logger = new ArrayLogger();
    }

    private function tracking(string $trackingBearer = 'trk-bearer', string $token = 'cp-token-1234'): ShipmentTracking
    {
        $credentials = new FixedCredentials(new Credentials('ecom-aaaa', $trackingBearer, $token, false));

        return new ShipmentTracking(
            new TrackingClient($this->transport, $credentials),
            new EcomClient($this->transport, $credentials),
            $this->rows,
            $this->mutex,
            new AuthFlags($this->state),
            $this->logger,
            static fn(): int => self::NOW,
        );
    }

    /** @return array{tracking: ?string, ecom: ?string} */
    private function failures(): array
    {
        $flags = new AuthFlags($this->state);

        return ['tracking' => $flags->reason(AuthFlags::TRACKING), 'ecom' => $flags->reason(AuthFlags::ECOM)];
    }

    /** @param array<string, mixed> $extra */
    private function shipment(int $orderId, string $barcode, array $extra = [], int $createdAgo = 86400): void
    {
        $this->rows->add($orderId, ['uuid' => "u-{$orderId}", 'barcode' => $barcode, 'status' => 'REGISTERED', 'delivery_price' => 50.0, 'cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => true]);
        foreach ($extra + ['created_at' => date('Y-m-d H:i:s', self::NOW - $createdAgo)] as $field => $value) {
            $this->rows->rows[$orderId]->{$field} = $value;
        }
        $row = $this->rows->rows[$orderId];
        $row->status_group = $extra['status_group'] ?? ShipmentStatus::group($row->event_code ?? null, $row->status, $row->event_reason ?? null);
    }

    private function barcode(int $i): string
    {
        return sprintf('05%011d', $i);
    }

    /** @return list<string> */
    private function bodyBarcodes(int $request): array
    {
        return json_decode($this->transport->requests()[$request]->body, true);
    }

    public function testBatchesOfHundred(): void
    {
        for ($i = 1; $i <= 250; $i++) {
            $this->shipment($i, $this->barcode($i));
        }
        $this->transport->json(200, [])->json(200, [])->json(200, []);

        $this->tracking()->tick();

        self::assertCount(3, $this->transport->requests());
        self::assertSame([100, 100, 50], [count($this->bodyBarcodes(0)), count($this->bodyBarcodes(1)), count($this->bodyBarcodes(2))]);
        self::assertSame([], $this->mutex->held, 'mutex is released');
    }

    public function testAtMostTenBatchesPerTick(): void
    {
        for ($i = 1; $i <= 1100; $i++) {
            $this->shipment($i, $this->barcode($i));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->transport->json(200, []);
        }

        $this->tracking()->tick();

        self::assertCount(10, $this->transport->requests());
    }

    public function testStoresLatestEventAndSkipsNothingElse(): void
    {
        $this->shipment(1, $this->barcode(1));
        $this->transport->json(200, [
            ['barcode' => $this->barcode(1), 'step' => 5, 'event' => '21700', 'name' => 'Прибуло', 'date' => '2026-11-09T10:00:00'],
        ]);

        $this->tracking()->tick();

        $row = $this->rows->forOrder(1);
        self::assertSame(21700, $row->event_code);
        self::assertSame('Прибуло', $row->event_name);
        self::assertSame(date('Y-m-d H:i:s', self::NOW), $row->checked_at);
        self::assertSame('arrived', ShipmentStatus::group($row->event_code, $row->status, $row->event_reason));
    }

    public function testReturnedByReasonIsStoredAndFinal(): void
    {
        $this->shipment(1, $this->barcode(1));
        $this->transport->json(200, [
            ['barcode' => $this->barcode(1), 'event' => 41000, 'name' => 'Вручено', 'date' => '2026-11-09T10:00:00', 'eventReason_id' => 10],
        ]);

        $this->tracking()->tick();
        $this->rows->rows[1]->checked_at = date('Y-m-d H:i:s', self::NOW - 7200);
        $this->tracking()->tick();

        self::assertCount(1, $this->transport->requests(), 'a returned shipment is final and is not asked again');
    }

    public function testSkipsFinalAndOlderThanSixtyDays(): void
    {
        $this->shipment(1, $this->barcode(1), ['event_code' => 41000, 'event_reason' => null, 'checked_at' => null]);
        $this->shipment(2, $this->barcode(2), ['status' => 'CANCELED']);
        $this->shipment(3, $this->barcode(3), [], 61 * 86400);
        $this->shipment(4, $this->barcode(4), [], 59 * 86400);
        $this->shipment(5, $this->barcode(5), ['checked_at' => date('Y-m-d H:i:s', self::NOW - 600)]);
        $this->transport->json(200, []);

        $this->tracking()->tick();

        self::assertSame([$this->barcode(4)], $this->bodyBarcodes(0));
    }

    public function testLifecycleOnlyForCreatedHourly(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'CREATED']);
        $this->shipment(2, $this->barcode(2), ['status' => 'REGISTERED']);
        $this->shipment(3, $this->barcode(3), ['status' => 'CREATED', 'lifecycle_checked_at' => date('Y-m-d H:i:s', self::NOW - 1800)]);
        $this->shipment(4, $this->barcode(4), ['status' => 'CREATED', 'event_code' => 10601, 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->transport->json(200, [])->json(200, ['status' => 'REGISTERED']);

        $this->tracking()->tick();

        $urls = array_map(static fn($r) => $r->url, $this->transport->requests());
        self::assertCount(2, $urls);
        self::assertStringEndsWith('/statuses/last', $urls[0]);
        self::assertStringContainsString('shipments/u-1/lifecycle?token=', $urls[1]);
        self::assertSame('REGISTERED', $this->rows->forOrder(1)->status);

        $this->transport = new FakeTransport();
        $this->transport->json(200, []);
        foreach ($this->rows->rows as $row) {
            $row->checked_at = null;
        }
        $this->tracking()->tick();
        self::assertCount(1, $this->transport->requests(), 'checked within the hour: no lifecycle call');
    }

    public function testLifecycleCapAndNotFoundIsRemembered(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->shipment($i, $this->barcode($i), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        }
        for ($i = 0; $i < 50; $i++) {
            $this->transport->push(new HttpResponse(404, '{"message":"not found"}'));
        }

        $this->tracking()->tick();

        self::assertCount(50, $this->transport->requests());
        self::assertSame([], $this->mutex->held);
    }

    public function testAuthFailureStopsAndFlags(): void
    {
        $this->shipment(1, $this->barcode(1));
        $this->shipment(2, $this->barcode(2), ['status' => 'CREATED']);
        $this->transport->push(new HttpResponse(403, '{"message":"forbidden"}'));

        $this->tracking()->tick();

        self::assertCount(1, $this->transport->requests(), 'tick stops: lifecycle is not asked');
        self::assertSame(['tracking' => 'bad_bearer', 'ecom' => null], $this->failures());
        self::assertNull($this->rows->forOrder(1)->event_code);
        self::assertSame([], $this->mutex->held);
    }

    public function testSuccessClearsFlag(): void
    {
        $this->state->set('tracking_auth_failed', 'bad_bearer');
        $this->state->set('ecom_auth_failed', 'bad_token');
        $this->shipment(1, $this->barcode(1));
        $this->transport->json(200, []);

        $this->tracking()->tick();

        self::assertSame(['tracking' => null, 'ecom' => 'bad_token'], $this->failures(), 'tracking success does not clear the eCom flag');
    }

    public function testBusyMutexDoesNothing(): void
    {
        $this->shipment(1, $this->barcode(1));
        $this->mutex->held['track'] = true;

        $this->tracking()->tick();

        self::assertSame([], $this->transport->requests());
    }

    public function testRefreshStoresHistory(): void
    {
        $this->shipment(7, $this->barcode(7));
        $this->transport->json(200, [
            ['step' => 2, 'event' => '41000', 'name' => 'Вручено', 'date' => '2026-11-10T15:00:00', 'eventReason_id' => 10],
            ['step' => 1, 'event' => 20700, 'name' => 'У дорозі', 'date' => '2026-11-08T09:30:00'],
        ]);

        $outcome = $this->tracking()->refresh(7);

        self::assertTrue($outcome->ok);
        self::assertStringContainsString('/statuses?barcode=' . $this->barcode(7), $this->transport->requests()[0]->url);
        $row = $this->rows->forOrder(7);
        self::assertSame(41000, $row->event_code);
        self::assertSame(10, $row->event_reason);
        self::assertSame('returned', $row->status_group, 'the full history decides the group');
        self::assertObjectNotHasProperty('history_json', $row, 'nothing reads a stored history');
    }

    public function testRefreshWithoutShipmentIsRefused(): void
    {
        self::assertFalse($this->tracking()->refresh(99)->ok);
        $this->rows->reserve(5);
        self::assertFalse($this->tracking()->refresh(5)->ok);
        self::assertSame([], $this->transport->requests());
    }

    public function testRefreshReportsApiFailure(): void
    {
        $this->shipment(7, $this->barcode(7));
        $this->transport->push(new HttpResponse(403, '{"message":"forbidden"}'));

        $outcome = $this->tracking()->refresh(7);

        self::assertFalse($outcome->ok);
        self::assertSame('bad_bearer', $this->failures()['tracking']);
    }

    public function testEmptyTrackingBearerIsNotAnAuthFailure(): void
    {
        $this->shipment(1, $this->barcode(1));
        $this->shipment(2, $this->barcode(2), ['status' => 'CREATED']);
        $this->transport->json(200, ['status' => 'CREATED']);

        $this->tracking('')->tick();

        self::assertCount(1, $this->transport->requests(), 'tracking is skipped, lifecycle still polled');
        self::assertStringContainsString('lifecycle', $this->transport->requests()[0]->url);
        self::assertSame(['tracking' => null, 'ecom' => null], $this->failures());
    }

    public function testUnconfiguredTrackingDropsItsOldFlag(): void
    {
        $this->state->set('tracking_auth_failed', 'bad_bearer');

        $this->tracking('')->tick();

        self::assertNull($this->failures()['tracking']);
    }

    public function testRefreshWithoutTrackingKeySaysSo(): void
    {
        $this->shipment(7, $this->barcode(7));

        $outcome = $this->tracking('')->refresh(7);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__ukrposhta__track_err_not_configured', $outcome->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    public function testRefreshNamesTheTrackingKeyNotTheEcomOne(): void
    {
        $this->shipment(7, $this->barcode(7));
        $this->transport->push(new HttpResponse(403, '{"message":"forbidden"}'));
        $this->transport->push(new HttpResponse(401, '{"message":"unauthorized"}'));

        foreach ([1, 2] as $_) {
            $issue = $this->tracking()->refresh(7)->issues[0];
            self::assertSame('sviat__ukrposhta__track_err_bad_key', $issue['text']);
            self::assertSame('sviat__ukrposhta__ship_advice_key', $issue['advice']);
        }
    }

    /** Один зламаний пакет не тримає чергу: інакше ті самі 100 рядків щотіку падали б першими. */
    public function testFailedBatchIsMarkedCheckedAndTheNextOneRuns(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->shipment($i, $this->barcode($i));
        }
        $this->transport->push(new HttpResponse(400, '{"message":"boom ?token=leak-me"}'))->json(200, [
            ['barcode' => $this->barcode(150), 'event' => 21700, 'name' => 'Прибуло', 'date' => '2026-11-09T10:00:00'],
        ]);

        $this->tracking()->tick();

        self::assertCount(2, $this->transport->requests());
        self::assertSame(date('Y-m-d H:i:s', self::NOW), $this->rows->forOrder(1)->checked_at, 'the failed batch goes to the back of the queue');
        self::assertNull($this->rows->forOrder(1)->event_code);
        self::assertSame(21700, $this->rows->forOrder(150)->event_code);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('UkrposhtaApiException', $this->logger->text());
        self::assertStringNotContainsString('trk-bearer', $this->logger->text());
        self::assertStringNotContainsString('leak-me', $this->logger->text());
    }

    /** Недоступна чи зламана Укрпошта: решту пакетів у цьому тіку питати марно, і черга не зсувається. */
    public function testOutageStopsTrackingWithoutMarkingTheBatch(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->shipment($i, $this->barcode($i));
        }
        $this->transport->push(new HttpResponse(502, '{"message":"bad gateway"}'));

        $this->tracking()->tick();

        self::assertCount(1, $this->transport->requests());
        self::assertNull($this->rows->forOrder(1)->checked_at ?? null);
    }

    /** Відмова за одним відправленням не тримає lifecycle решти: рядок іде в кінець черги. */
    public function testLifecycleRowRefusalIsStampedAndTheNextRowRuns(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->shipment(2, $this->barcode(2), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->transport->json(400, ['message' => 'bad uuid'])->json(200, ['status' => 'REGISTERED']);

        $this->tracking()->tick();

        self::assertCount(2, $this->transport->requests());
        self::assertSame('CREATED', $this->rows->forOrder(1)->status);
        self::assertSame(date('Y-m-d H:i:s', self::NOW), $this->rows->forOrder(1)->lifecycle_checked_at);
        self::assertSame('REGISTERED', $this->rows->forOrder(2)->status);
        self::assertCount(1, $this->logger->records);
    }

    public function testLifecycleOutageStopsWithoutStamping(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->shipment(2, $this->barcode(2), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->transport->json(503, ['message' => 'down']);

        $this->tracking()->tick();

        self::assertCount(1, $this->transport->requests());
        self::assertNull($this->rows->forOrder(1)->lifecycle_checked_at ?? null);
    }

    /** Не заданий ключ — не відмова Укрпошти: ні запиту, ні позначки, ні рядка в лозі щохвилини. */
    public function testLifecycleSkippedWhileEcomIsNotConfigured(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);

        $this->tracking('trk-bearer', '')->tick();

        self::assertSame([], $this->transport->requests());
        self::assertSame(['tracking' => null, 'ecom' => null], $this->failures());
        self::assertSame([], $this->logger->records);
    }

    public function testLongValuesAreCutToTheColumns(): void
    {
        $this->shipment(1, $this->barcode(1));
        $this->shipment(2, $this->barcode(2), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->transport->json(200, [
            ['barcode' => $this->barcode(1), 'event' => 21700, 'name' => str_repeat('Ж', 300), 'date' => '2026-11-09T10:00:00'],
        ])->json(200, ['status' => str_repeat('S', 40)]);

        $this->tracking()->tick();

        self::assertSame(255, mb_strlen($this->rows->forOrder(1)->event_name));
        self::assertSame(32, strlen($this->rows->forOrder(2)->status));
    }

    public function testNonAuthTrackingErrorStillPollsLifecycle(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'CREATED']);
        $this->transport->push(new HttpResponse(500, '{"message":"boom"}'))->json(200, ['status' => 'REGISTERED']);

        $this->tracking()->tick();

        self::assertCount(2, $this->transport->requests());
        self::assertSame('REGISTERED', $this->rows->forOrder(1)->status);
        self::assertSame(['tracking' => null, 'ecom' => null], $this->failures());
    }

    public function testLifecycleAnswerShapes(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->shipment(2, $this->barcode(2), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->shipment(3, $this->barcode(3), ['status' => 'CREATED', 'checked_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $this->transport->json(200, ['status' => 'REGISTERED'])
            ->json(200, [['status' => 'CREATED'], ['status' => 'DELIVERING']])
            ->json(200, ['unexpected' => true]);

        $this->tracking()->tick();

        self::assertSame('REGISTERED', $this->rows->forOrder(1)->status);
        self::assertSame('DELIVERING', $this->rows->forOrder(2)->status);
        self::assertSame('CREATED', $this->rows->forOrder(3)->status);
        foreach ([1, 2, 3] as $order) {
            self::assertSame(date('Y-m-d H:i:s', self::NOW), $this->rows->forOrder($order)->lifecycle_checked_at, "order {$order} is not re-polled every tick");
        }
    }

    public function testReturnWithoutReasonIsReturnedAfterReturning(): void
    {
        $this->shipment(1, $this->barcode(1), ['event_code' => 31200, 'event_at' => '2026-11-09 10:00:00']);
        $this->transport->json(200, [['barcode' => $this->barcode(1), 'event' => 41000, 'name' => 'Вручено', 'date' => '2026-11-10T10:00:00']]);

        $this->tracking()->tick();

        self::assertSame('returned', $this->rows->forOrder(1)->status_group);
    }

    public function testRefreshHistoryWithReturningMakesReturned(): void
    {
        $this->shipment(7, $this->barcode(7));
        $this->transport->json(200, [
            ['event' => 31200, 'name' => 'Повертається', 'date' => '2026-11-08T09:30:00'],
            ['event' => 41000, 'name' => 'Вручено', 'date' => '2026-11-10T15:00:00'],
        ]);

        $this->tracking()->refresh(7);

        self::assertSame('returned', $this->rows->forOrder(7)->status_group);
    }

    public function testGroupIsPersistedAndFiltersByIt(): void
    {
        $this->shipment(1, $this->barcode(1), ['status' => 'REGISTERED', 'status_group' => null]);
        $this->shipment(2, $this->barcode(2), ['status' => 'REGISTERED', 'status_group' => 'received']);
        $this->transport->json(200, []);

        $this->tracking()->tick();

        self::assertSame([$this->barcode(1)], $this->bodyBarcodes(0), 'NULL group is still tracked, a final group is not');
    }
}
