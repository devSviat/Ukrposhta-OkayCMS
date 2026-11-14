<?php

namespace Modules\Sviat\Ukrposhta\Quote;

use Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryQuote;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteKey;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteSession;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteThrottle;
use PHPUnit\Framework\TestCase;

class QuoteSessionTest extends TestCase
{
    private const int NOW = 1_900_000_000;

    private static function cart(array $lines, float $total): object
    {
        return (object)['total_price' => $total, 'purchases' => array_map(static fn(array $l): object => (object)['variant_id' => $l[0], 'amount' => $l[1]], $lines)];
    }

    public function testKeyFollowsVariantWeight(): void
    {
        $cart = static fn(float $weight): object => (object)['total_price' => 300.0, 'purchases' => [
            (object)['variant_id' => 1, 'amount' => 2, 'variant' => (object)['id' => 1, 'weight' => $weight]],
        ]];
        $key = QuoteKey::for(4, 7162, false, $cart(1.5));

        self::assertSame($key, QuoteKey::for(4, 7162, false, $cart(1.5)));
        self::assertNotSame($key, QuoteKey::for(4, 7162, false, $cart(3.0)));
    }

    public function testKeyFollowsEveryInputButNotTheOrderOfLines(): void
    {
        $cart = self::cart([[1, 2], [5, 1]], 300.0);
        $key = QuoteKey::for(4, 7162, false, $cart);

        self::assertSame($key, QuoteKey::for(4, 7162, false, self::cart([[5, 1], [1, 2]], 300.0)));
        self::assertNotSame($key, QuoteKey::for(5, 7162, false, $cart));
        self::assertNotSame($key, QuoteKey::for(4, 7163, false, $cart));
        self::assertNotSame($key, QuoteKey::for(4, 7162, true, $cart));
        self::assertNotSame($key, QuoteKey::for(4, 7162, false, self::cart([[1, 2], [5, 1]], 301.0)));
        self::assertNotSame($key, QuoteKey::for(4, 7162, false, self::cart([[1, 3], [5, 1]], 300.0)));
    }

    public function testQuoteLivesAnHour(): void
    {
        $bag = [];
        $quote = new DeliveryQuote(135.0, 40.0, 'api', null);
        QuoteSession::put($bag, 'k', $quote, self::NOW);

        self::assertEquals($quote, QuoteSession::get($bag, 'k', self::NOW + 3599));
        self::assertNull(QuoteSession::get($bag, 'k', self::NOW + 3601));
        self::assertNull(QuoteSession::get($bag, 'other', self::NOW));
    }

    public function testAtMostTenQuotesAreKept(): void
    {
        $bag = [];
        for ($i = 0; $i < 12; $i++) {
            QuoteSession::put($bag, "k{$i}", new DeliveryQuote((float)$i, null, 'api', null), self::NOW + $i);
        }

        self::assertNull(QuoteSession::get($bag, 'k0', self::NOW + 20));
        self::assertNull(QuoteSession::get($bag, 'k1', self::NOW + 20));
        self::assertSame(11.0, QuoteSession::get($bag, 'k11', self::NOW + 20)?->price);
    }

    public function testCorruptSessionDataIsIgnored(): void
    {
        $bag = ['quotes' => ['k' => 'garbage'], 'chosen' => 'x'];

        self::assertNull(QuoteSession::get($bag, 'k', self::NOW));
        self::assertNull(QuoteSession::chosen($bag, 4));
    }

    public function testLastChoicePerDelivery(): void
    {
        $bag = [];
        QuoteSession::choose($bag, 4, 7162, true);
        QuoteSession::choose($bag, 9, 118064, false);

        self::assertSame(['office' => 7162, 'cod' => true], QuoteSession::chosen($bag, 4));
        self::assertSame(['office' => 118064, 'cod' => false], QuoteSession::chosen($bag, 9));
        self::assertNull(QuoteSession::chosen($bag, 5));
    }

    public function testThrottle(): void
    {
        $bag = [];
        for ($i = 0; $i < 20; $i++) {
            self::assertTrue(QuoteThrottle::allow($bag, self::NOW + $i));
        }
        self::assertFalse(QuoteThrottle::allow($bag, self::NOW + 30));
        self::assertTrue(QuoteThrottle::allow($bag, self::NOW + 601));
    }

    public function testUnreachableIsKeptForTheOrderButRetriedByTheCart(): void
    {
        $bag = [];
        QuoteSession::put($bag, 'k', new DeliveryQuote(null, null, 'fallback', 'unreachable'), self::NOW);

        self::assertSame('unreachable', QuoteSession::get($bag, 'k', self::NOW + 59, retryUnreachableAfter: 60)?->problem);
        self::assertNull(QuoteSession::get($bag, 'k', self::NOW + 60, retryUnreachableAfter: 60));
        self::assertSame('unreachable', QuoteSession::get($bag, 'k', self::NOW + 600)?->problem, 'checkout keeps what the cart showed');
    }
}
