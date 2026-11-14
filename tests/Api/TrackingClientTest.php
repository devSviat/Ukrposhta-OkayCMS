<?php

namespace Modules\Sviat\Ukrposhta\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use PHPUnit\Framework\TestCase;

class TrackingClientTest extends TestCase
{
    private FakeTransport $transport;
    private TrackingClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new TrackingClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk-cccc', 'cp-bbbb', false)));
    }

    public function testRejectsMoreThan100Barcodes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->lastStatuses(array_map(strval(...), range(1, 101)));
    }

    public function testUsesTrackingBearer(): void
    {
        $this->transport->json(200, [['barcode' => '1', 'step' => 0]]);

        $rows = $this->client->lastStatuses(['1']);

        $this->assertSame([['barcode' => '1', 'step' => 0]], $rows);
        $request = $this->transport->requests()[0];
        $this->assertSame('POST', $request->method);
        $this->assertSame('https://www.ukrposhta.ua/status-tracking/0.0.1/statuses/last', $request->url);
        $this->assertSame('Bearer trk-cccc', $request->headers['Authorization']);
        $this->assertSame('["1"]', $request->body);
    }

    public function testExactly100BarcodesPass(): void
    {
        $this->transport->json(200, []);

        $this->assertSame([], $this->client->lastStatuses(array_map(strval(...), range(1, 100))));
    }

    public function testMissingTrackingBearerFailsBeforeRequest(): void
    {
        $client = new TrackingClient($this->transport, new FixedCredentials(new Credentials('e', '', 'c', false)));

        $this->expectException(\Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException::class);
        try {
            $client->lastStatuses(['1']);
        } finally {
            $this->assertSame([], $this->transport->requests());
        }
    }
}
