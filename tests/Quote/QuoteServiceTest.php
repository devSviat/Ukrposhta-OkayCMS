<?php

namespace Modules\Sviat\Ukrposhta\Quote;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;
use Okay\Modules\Sviat\Ukrposhta\Quote\Parcel;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteRequest;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteService;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class QuoteServiceTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryState $state;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
    }

    private function service(): QuoteService
    {
        return new QuoteService(new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false))), new AuthFlags($this->state));
    }

    private static function config(array $override = []): ShippingConfig
    {
        $settings = $override + [ShippingConfig::SENDER_POSTCODE => '01001', ShippingConfig::TYPE => 'EXPRESS'];

        return ShippingConfig::fromSettings(static fn(string $k): mixed => $settings[$k] ?? null);
    }

    private static function request(ShippingConfig $config, bool $cod = false): QuoteRequest
    {
        return new QuoteRequest($config, '79000', new Parcel(1250, 30, 20, 15), 1999.9, $cod);
    }

    public function testRequestBodyForCodToOffice(): void
    {
        $this->transport->json(200, ['deliveryPrice' => 88.5, 'postPayDeliveryPrice' => 24.0]);

        $quote = $this->service()->quote(self::request(self::config([ShippingConfig::DISCOUNT => '10']), cod: true));

        $request = $this->transport->requests()[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://www.ukrposhta.ua/ecom/0.0.1/domestic/delivery-price', $request->url, 'no token in the price call');
        self::assertSame([
            'addressFrom' => ['postcode' => '01001'],
            'addressTo' => ['postcode' => '79000'],
            'type' => 'EXPRESS',
            'deliveryType' => 'W2W',
            'weight' => 1250,
            'length' => 30,
            'width' => 20,
            'height' => 15,
            'declaredPrice' => 1999.9,
            'postPay' => 1999.9,
            'discounts' => [['description' => 'contract', 'rate' => 10]],
        ], json_decode($request->body, true));
        self::assertSame(88.5, $quote->price);
        self::assertSame(24.0, $quote->codFee);
        self::assertSame('api', $quote->source);
        self::assertNull($quote->problem);
    }

    public function testPrepaidWithoutDeclaredValueOrDiscountSendsNoOptionalKeys(): void
    {
        $this->transport->json(200, ['deliveryPrice' => 60]);

        $quote = $this->service()->quote(self::request(self::config([ShippingConfig::DECLARE_TOTAL => '0'])));

        $body = json_decode($this->transport->requests()[0]->body, true);
        self::assertArrayNotHasKey('declaredPrice', $body);
        self::assertArrayNotHasKey('postPay', $body);
        self::assertArrayNotHasKey('discounts', $body);
        self::assertSame(60.0, $quote->price);
        self::assertNull($quote->codFee);
    }

    public function testServerErrorGivesFallbackNotException(): void
    {
        $this->transport->json(503, ['message' => 'down']);

        $quote = $this->service()->quote(self::request(self::config()));

        self::assertNull($quote->price);
        self::assertSame('fallback', $quote->source);
        self::assertSame('unreachable', $quote->problem);
        self::assertTrue($quote->unpriced());
    }

    public function testTransportFailureAndGarbageGiveFallback(): void
    {
        $this->transport->push(new TransportException('connection refused'));
        self::assertSame('unreachable', $this->service()->quote(self::request(self::config()))->problem);

        $this->transport->json(400, ['message' => 'bad postcode']);
        self::assertSame('rejected', $this->service()->quote(self::request(self::config()))->problem);

        $this->transport->json(200, ['deliveryPrice' => 'abc']);
        self::assertSame('rejected', $this->service()->quote(self::request(self::config()))->problem);

        $this->transport->json(200, []);
        $quote = $this->service()->quote(self::request(self::config()));
        self::assertSame('rejected', $quote->problem);
        self::assertSame('fallback', $quote->source);
    }

    public function testMissingSenderPostcodeIsNoSender(): void
    {
        $quote = $this->service()->quote(self::request(self::config([ShippingConfig::SENDER_POSTCODE => '  '])));

        self::assertSame('no_sender', $quote->problem);
        self::assertSame('none', $quote->source);
        self::assertSame([], $this->transport->requests(), 'nothing is sent without a sender');
    }

    /** Відкликаний bearer: кошик лишається без ціни, і налаштування мають про це сказати; успіх знімає позначку. */
    public function testAuthRefusalIsFlaggedUntilNextPrice(): void
    {
        $this->transport->json(403, ['message' => 'Forbidden']);
        self::assertSame('rejected', $this->service()->quote(self::request(self::config()))->problem);
        self::assertSame('bad_bearer', new AuthFlags($this->state)->reason(AuthFlags::QUOTE));

        $this->transport->json(400, ['message' => 'bad postcode']);
        $this->service()->quote(self::request(self::config()));
        self::assertSame('bad_bearer', new AuthFlags($this->state)->reason(AuthFlags::QUOTE), 'a rejected address says nothing about the key');

        $this->transport->json(200, ['deliveryPrice' => 60]);
        $this->service()->quote(self::request(self::config()));
        self::assertNull(new AuthFlags($this->state)->reason(AuthFlags::QUOTE));
    }
}
