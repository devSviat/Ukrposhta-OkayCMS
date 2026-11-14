<?php

namespace Modules\Sviat\Ukrposhta\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use PHPUnit\Framework\TestCase;

class ClassifierClientTest extends TestCase
{
    private FakeTransport $transport;
    private ClassifierClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new ClassifierClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-bbbb', false)));
    }

    public function testSingleEntryObjectBecomesList(): void
    {
        $this->transport->json(200, ['Entries' => ['Entry' => ['REGION_ID' => '270']]]);

        $this->assertSame([['REGION_ID' => '270']], $this->client->rows('get_regions_by_region_ua', ['region_name' => 'Київ']));
    }

    public function testListOfEntriesIsReturnedAsIs(): void
    {
        $this->transport->json(200, ['Entries' => ['Entry' => [['REGION_ID' => '1'], ['REGION_ID' => '2']]]]);

        $this->assertCount(2, $this->client->rows('get_regions_by_region_ua', []));
    }

    public function testEmptyEntriesIsEmptyList(): void
    {
        $this->transport->json(200, ['Entries' => []]);
        $this->transport->json(200, []);

        $this->assertSame([], $this->client->rows('get_regions_by_region_ua', []));
        $this->assertSame([], $this->client->rows('get_regions_by_region_ua', []));
    }

    public function testSendsBearerAcceptJsonAndWwwHost(): void
    {
        $this->transport->json(200, ['Entries' => []]);

        $this->client->rows('get_regions_by_region_ua', ['region_name' => 'Київ']);

        $request = $this->transport->requests()[0];
        $this->assertSame('GET', $request->method);
        $this->assertStringStartsWith('https://www.ukrposhta.ua/address-classifier-ws/get_regions_by_region_ua?', $request->url);
        $this->assertStringContainsString('region_name=' . rawurlencode('Київ'), $request->url);
        $this->assertSame('Bearer ecom-aaaa', $request->headers['Authorization']);
        $this->assertSame('application/json', $request->headers['Accept']);
    }

    public function testSandboxDoesNotChangeClassifierHost(): void
    {
        $client = new ClassifierClient($this->transport, new FixedCredentials(new Credentials('e', 't', 'c', true)));
        $this->transport->json(200, ['Entries' => []]);

        $client->rows('m', []);

        $this->assertStringStartsWith('https://www.ukrposhta.ua/address-classifier-ws/m?', $this->transport->requests()[0]->url);
    }

    public function testServerErrorAndUnreachableAreTyped(): void
    {
        $this->transport->json(503, []);
        $this->transport->push(new TransportException('timeout'));

        $kinds = [];
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->client->rows('m', []);
            } catch (UkrposhtaApiException $e) {
                $kinds[] = $e->kind;
            }
        }

        $this->assertSame([ApiErrorKind::ServerError, ApiErrorKind::Unreachable], $kinds);
    }

    public function testNonJsonAnswerIsMalformed(): void
    {
        $this->transport->push(new \Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse(200, '<xml/>'));

        $this->expectException(UkrposhtaApiException::class);
        $this->client->rows('m', []);
    }

    public function testBearerNeverLeaksIntoErrors(): void
    {
        $this->transport->json(403, []);

        try {
            $this->client->rows('m', []);
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::BadBearer, $e->kind);
            $this->assertStringNotContainsString('ecom-aaaa', $e->getMessage());
        }
    }
}
