<?php

namespace Modules\Sviat\Ukrposhta\Quote;

use Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryQuote;
use Okay\Modules\Sviat\Ukrposhta\Quote\PriceDecision;
use PHPUnit\Framework\TestCase;

class PriceDecisionTest extends TestCase
{
    private const array CORE = ['delivery_price' => 70.0, 'separate_delivery' => 0];

    public function testCalculatedPriceGoesToTheOrder(): void
    {
        $decision = PriceDecision::forOrder(new DeliveryQuote(135.0, 40.0, 'api', null), self::CORE);

        self::assertSame(['delivery_price' => 135.0, 'separate_delivery' => 0], $decision['info']);
        self::assertSame('api', $decision['source']);
    }

    public function testFailedCalculationChargesNoDelivery(): void
    {
        foreach (['unreachable', 'rejected', 'no_uah'] as $problem) {
            $decision = PriceDecision::forOrder(new DeliveryQuote(null, null, 'fallback', $problem), self::CORE, coreDefault: 70.0);

            self::assertSame(['delivery_price' => 0.0, 'separate_delivery' => 0], $decision['info'], $problem);
            self::assertSame('fallback', $decision['source']);
        }
    }

    /** Ціну, яку браузер прислав у формі, рішення не бачить: з ядра приходить лише те, що воно саме порахувало. */
    public function testPostedPriceIsIgnored(): void
    {
        $posted = ['delivery_price' => 1.0, 'separate_delivery' => 0];

        self::assertSame(135.0, PriceDecision::forOrder(new DeliveryQuote(135.0, null, 'api', null), $posted)['info']['delivery_price']);
        self::assertSame(0.0, PriceDecision::forOrder(new DeliveryQuote(null, null, 'fallback', 'unreachable'), $posted)['info']['delivery_price']);
    }

    public function testNoQuoteMeansTheCoreDecides(): void
    {
        self::assertSame(['info' => self::CORE, 'source' => null], PriceDecision::forOrder(null, self::CORE));
        self::assertSame(['info' => self::CORE, 'source' => 'none'], PriceDecision::forOrder(new DeliveryQuote(null, null, 'none', null), self::CORE));
    }

    public function testFreeByTheCoreStaysFree(): void
    {
        $free = ['delivery_price' => 0, 'separate_delivery' => 0];

        self::assertSame($free, PriceDecision::forOrder(new DeliveryQuote(95.0, null, 'api', null), $free, freeByCore: true)['info']);
    }

    public function testPriceSetByAnotherModuleIsKept(): void
    {
        $promo = ['delivery_price' => 0, 'separate_delivery' => 0];

        self::assertSame(['info' => $promo, 'source' => 'external'], PriceDecision::forOrder(new DeliveryQuote(172.0, null, 'api', null), $promo, coreDefault: 70.0));
    }
}
