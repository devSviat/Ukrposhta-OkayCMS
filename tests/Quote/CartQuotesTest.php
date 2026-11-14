<?php

namespace Modules\Sviat\Ukrposhta\Quote;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';

use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryMutex;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\CartQuotes;
use Okay\Modules\Sviat\Ukrposhta\Quote\ClientAddress;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteBudget;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteService;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class CartQuotesTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryState $state;
    private ?string $ip = '203.0.113.7';
    private CartQuotes $quotes;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();

        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static fn(string $k): mixed => $k === ShippingConfig::SENDER_POSTCODE ? '01001' : null);

        $offices = $this->createStub(OfficeEntity::class);
        $offices->method('get')->willReturn((object)['id' => 7, 'postcode' => '79000']);
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturn((object)['id' => 1]);
        $payments = $this->createStub(PaymentsEntity::class);
        $payments->method('get')->willReturn(null);
        $entities = $this->createStub(EntityFactory::class);
        $entities->method('get')->willReturnCallback(static fn(string $class): object => match ($class) {
            OfficeEntity::class => $offices,
            CurrenciesEntity::class => $currencies,
            PaymentsEntity::class => $payments,
        });
        $money = $this->createStub(Money::class);
        $money->method('convert')->willReturnCallback(static fn(float $amount): string => (string)$amount);
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(5);

        $client = $this->createStub(ClientAddress::class);
        $client->method('address')->willReturnCallback(fn(): ?string => $this->ip);

        $this->quotes = new CartQuotes(
            new QuoteService(new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false))), new AuthFlags(new InMemoryState())),
            $settings,
            $entities,
            new CodPayments($entities),
            $module,
            $money,
            $this->state,
            new UahTotal($entities, $money),
            new InMemoryMutex(),
            $client,
        );
    }

    private static function delivery(): object
    {
        return (object)['id' => 3, 'module_id' => 5, 'free_from' => 1_000_000, 'hide_front_delivery_price' => 0];
    }

    private static function cart(float $total = 500.0): object
    {
        return (object)['total_price' => $total, 'purchases' => [(object)['variant_id' => 1, 'amount' => 1, 'variant' => (object)['id' => 1, 'weight' => 1.0]]]];
    }

    private function answers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->transport->json(200, ['deliveryPrice' => 80]);
        }
    }

    public function testOneIpWithFreshSessionsHitsItsShareAndOthersStillGetQuotes(): void
    {
        $this->answers(QuoteBudget::VISITOR_LIMIT + 1);
        for ($i = 0; $i < QuoteBudget::VISITOR_LIMIT; $i++) {
            $_SESSION = [];
            self::assertNull($this->quotes->quote(self::delivery(), 7, 0, self::cart())->problem, "request {$i}");
        }

        $_SESSION = [];
        self::assertSame('busy', $this->quotes->quote(self::delivery(), 7, 0, self::cart())->problem);

        $this->ip = '203.0.113.8';
        $_SESSION = [];
        $quote = $this->quotes->quote(self::delivery(), 7, 0, self::cart());
        self::assertSame(80.0, $quote->price);
    }

    public function testSingleSessionIsThrottledAfterTwenty(): void
    {
        $this->answers(21);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(80.0, $this->quotes->quote(self::delivery(), 7, 0, self::cart(500.0 + $i))->price, "request {$i}");
        }

        self::assertSame('busy', $this->quotes->quote(self::delivery(), 7, 0, self::cart(900.0))->problem);
    }

    public function testOrderQuotesAreNeverChargedToTheBudget(): void
    {
        $this->answers(QuoteBudget::VISITOR_LIMIT + 20);
        for ($i = 0; $i < QuoteBudget::VISITOR_LIMIT + 20; $i++) {
            $_SESSION = [];
            self::assertSame(80.0, $this->quotes->quote(self::delivery(), 7, 0, self::cart(), forOrder: true)->price);
        }

        self::assertNull($this->state->get('quote_budget'));
    }

    /** Менеджер авторизований: його розрахунок не витрачає ліміти вітрини й не чіпає сесію відвідувача. */
    public function testAdminQuoteSkipsVisitorLimitsAndSession(): void
    {
        $this->answers(QuoteBudget::VISITOR_LIMIT + 20);
        $_SESSION = [];
        $hidden = (object)(['hide_front_delivery_price' => 1] + (array)self::delivery());
        for ($i = 0; $i < QuoteBudget::VISITOR_LIMIT + 20; $i++) {
            self::assertSame(80.0, $this->quotes->quote($hidden, 7, 0, self::cart(), forAdmin: true)->price, "request {$i}");
        }

        self::assertNull($this->state->get('quote_budget'));
        self::assertSame([], $_SESSION);
    }
}
