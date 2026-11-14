<?php

namespace Modules\Sviat\Ukrposhta\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use PHPUnit\Framework\TestCase;

class EcomClientTest extends TestCase
{
    private const string TOKEN = 'cp-token-1234';

    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function client(bool $sandbox = false): EcomClient
    {
        return new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', self::TOKEN, $sandbox)));
    }

    public function testTokenInQueryAndMaskedInErrors(): void
    {
        $this->transport->json(500, ['message' => 'boom']);

        try {
            $this->client()->call('POST', 'shipments', ['a' => 1]);
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::ServerError, $e->kind);
            $this->assertStringContainsString('token=***', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        $request = $this->transport->requests()[0];
        $this->assertSame('https://www.ukrposhta.ua/ecom/0.0.1/shipments?token=' . self::TOKEN, $request->url);
        $this->assertSame('Bearer ecom-aaaa', $request->headers['Authorization']);
        $this->assertSame('{"a":1}', $request->body);
    }

    public function testTokenIsMaskedWhenTransportFails(): void
    {
        $this->transport->push(new TransportException('cURL: could not reach https://x/y?token=' . self::TOKEN . '&a=1'));

        try {
            $this->client()->call('GET', 'shipments/1');
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::Unreachable, $e->kind);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringContainsString('token=***&a=1', $e->getMessage());
        }
    }

    public function testApiMessageIsMasked(): void
    {
        $this->transport->json(400, ['message' => 'bad ' . self::TOKEN]);

        try {
            $this->client()->call('POST', 'x', []);
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::Rejected, $e->kind);
            $this->assertSame('bad ***', $e->apiMessage);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testWithoutTokenOmitsQuery(): void
    {
        $this->transport->json(200, ['ok' => true]);

        $this->assertSame(['ok' => true], $this->client()->call('GET', 'addresses/1', null, false));
        $this->assertSame('https://www.ukrposhta.ua/ecom/0.0.1/addresses/1', $this->transport->requests()[0]->url);
        $this->assertNull($this->transport->requests()[0]->body);
    }

    public function testPathWithQueryGetsAmpersand(): void
    {
        $this->transport->json(200, []);

        $this->client()->call('GET', 'shipments?a=1');

        $this->assertSame('https://www.ukrposhta.ua/ecom/0.0.1/shipments?a=1&token=' . self::TOKEN, $this->transport->requests()[0]->url);
    }

    /** @return iterable<string, array{int, ApiErrorKind}> */
    public static function statuses(): iterable
    {
        yield '401' => [401, ApiErrorKind::BadToken];
        yield '403' => [403, ApiErrorKind::BadBearer];
        yield '404' => [404, ApiErrorKind::NotFound];
        yield '422' => [422, ApiErrorKind::Rejected];
        yield '502' => [502, ApiErrorKind::ServerError];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function testStatusMapsToKind(int $status, ApiErrorKind $kind): void
    {
        $this->transport->json($status, ['message' => 'x']);

        try {
            $this->client()->call('GET', 'x');
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame($kind, $e->kind);
        }
    }

    public function testStatus401IsBadToken(): void
    {
        $this->transport->json(401, []);

        $this->expectException(UkrposhtaApiException::class);
        $this->client()->call('GET', 'x');
    }

    public function testStatus403IsBadBearer(): void
    {
        $this->transport->json(403, []);

        try {
            $this->client()->call('GET', 'x');
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::BadBearer, $e->kind);
        }
    }

    public function testSandboxBase(): void
    {
        $this->transport->json(200, []);

        $this->client(true)->call('GET', 'x');

        $this->assertStringStartsWith('https://dev.ukrposhta.ua/ecom/0.0.1/x?', $this->transport->requests()[0]->url);
    }

    public function testNonJsonSuccessIsMalformed(): void
    {
        $this->transport->push(new HttpResponse(200, 'oops'));

        try {
            $this->client()->call('GET', 'x');
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::Malformed, $e->kind);
        }
    }

    public function testEmptySuccessBodyIsEmptyArray(): void
    {
        $this->transport->push(new HttpResponse(200, ''));

        $this->assertSame([], $this->client()->call('DELETE', 'x'));
    }

    public function testMissingCredentialsFailBeforeAnyRequest(): void
    {
        $client = new EcomClient($this->transport, new FixedCredentials(new Credentials('', '', '', false)));

        try {
            $client->call('GET', 'x');
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::NotConfigured, $e->kind, 'a key missing here is not a refusal by Ukrposhta');
        }
        $this->assertSame([], $this->transport->requests());
    }

    public function testPdfReturnsBytesFromFormsBase(): void
    {
        $this->transport->push(new HttpResponse(200, '%PDF-1.4 body'));

        $bytes = $this->client()->pdf('shipments/abc/sticker', ['size' => 'SIZE_A4']);

        $this->assertSame('%PDF-1.4 body', $bytes);
        $this->assertSame(
            'https://www.ukrposhta.ua/forms/ecom/0.0.1/shipments/abc/sticker?size=SIZE_A4&token=' . self::TOKEN,
            $this->transport->requests()[0]->url,
        );
    }

    public function testPdfErrorsAreMasked(): void
    {
        $this->transport->push(new HttpResponse(404, ''));

        try {
            $this->client()->pdf('shipments/abc/sticker', []);
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::NotFound, $e->kind);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testPdfWithJsonBodyIsMalformed(): void
    {
        $this->transport->push(new HttpResponse(200, '{"message":"No sticker for token=' . self::TOKEN . '"}'));

        try {
            $this->client()->pdf('shipments/abc/sticker', []);
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::Malformed, $e->kind);
            $this->assertSame('No sticker for token=***', $e->apiMessage);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testPdfPrefixAccepted(): void
    {
        $this->transport->push(new HttpResponse(200, '%PDF-1.7 x'));

        $this->assertSame('%PDF-1.7 x', $this->client()->pdf('shipments/abc/sticker', []));
    }

    public function testPdfPostSendsBodyAndChecksPrefix(): void
    {
        $this->transport->push(new HttpResponse(200, '%PDF-1.4 z'))->push(new HttpResponse(200, '{"a":1}'));

        $bytes = $this->client()->pdfPost('shipments/stickers-by-barcodes', ['size' => 'SIZE_A4'], ['B1' => new \stdClass()]);

        $this->assertSame('%PDF-1.4 z', $bytes);
        $request = $this->transport->requests()[0];
        $this->assertSame('POST', $request->method);
        $this->assertSame('{"B1":{}}', $request->body);
        $this->assertSame('application/json', $request->headers['Content-Type']);
        $this->assertStringContainsString('size=SIZE_A4&token=' . self::TOKEN, $request->url);

        $this->expectException(UkrposhtaApiException::class);
        $this->client()->pdfPost('shipments/stickers-by-barcodes', [], []);
    }

    public function testUnencodableBodyIsRejectedBeforeSending(): void
    {
        try {
            $this->client()->call('POST', 'shipments', ['description' => "bad \xff"]);
            $this->fail('expected exception');
        } catch (UkrposhtaApiException $e) {
            $this->assertSame(ApiErrorKind::Rejected, $e->kind);
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([], $this->transport->requests());
    }

    public function testPdfFailureTraceCarriesNoSecret(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $answers = [
                new HttpResponse(200, '{"message":"bad ' . self::TOKEN . ' / ' . rawurlencode(self::TOKEN) . '"}'),
                new HttpResponse(200, '<html>' . self::TOKEN . '</html>'),
            ];
            foreach ($answers as $case => $answer) {
                $this->transport->push($answer);
                try {
                    $this->client()->pdf('shipments/abc/sticker', []);
                    $this->fail('a non-PDF answer is an error');
                } catch (UkrposhtaApiException $e) {
                    $dump = (string)json_encode([$e->getMessage(), $e->apiMessage, $e->getTrace()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    $this->assertStringNotContainsString(self::TOKEN, $dump, "case {$case}");
                    $this->assertStringNotContainsString('ecom-aaaa', $dump, "case {$case}");
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string)$previous);
        }
    }
}
