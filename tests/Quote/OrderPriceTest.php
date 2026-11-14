<?php

namespace Modules\Sviat\Ukrposhta\Quote;

use Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryQuote;
use Okay\Modules\Sviat\Ukrposhta\Quote\OrderPrice;
use PHPUnit\Framework\TestCase;

/** Ціна замовлення: розрахунок сервера проти того, що дало ядро з рядка способу доставки. */
class OrderPriceTest extends TestCase
{
    private array $post = [];

    protected function setUp(): void
    {
        $this->post = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->post;
    }

    private static function delivery(): object
    {
        return (object)['id' => 6, 'price' => 50.0, 'free_from' => 5000.0, 'separate_payment' => 0];
    }

    /** Те, що повертає DeliveriesHelper::prepareDeliveryPriceInfo ядра для цього рядка способу. */
    private static function coreInfo(object $delivery, object $order): array
    {
        return ['delivery_price' => $delivery->free_from > $order->total_price ? $delivery->price : 0, 'separate_delivery' => $delivery->separate_payment];
    }

    public function testPostedDeliveryPriceIsIgnored(): void
    {
        $_POST['delivery_price'] = '1';
        $_POST['ukrposhta_delivery_price'] = [6 => '1'];
        $delivery = self::delivery();
        $order = (object)['id' => 10, 'total_price' => 1200.0, 'delivery_price' => 1.0, 'payment_method_id' => 3];

        $decision = OrderPrice::decide(new DeliveryQuote(80.0, null, 'api', null), self::coreInfo($delivery, $order), $delivery, $order);

        self::assertSame(80.0, $decision['info']['delivery_price']);
        self::assertSame('api', $decision['source']);
    }

    public function testUnreachableApiGivesNoDeliveryCharge(): void
    {
        $delivery = self::delivery();
        $order = (object)['total_price' => 1200.0];

        $decision = OrderPrice::decide(new DeliveryQuote(null, null, 'fallback', 'unreachable'), self::coreInfo($delivery, $order), $delivery, $order);

        self::assertSame(0.0, $decision['info']['delivery_price']);
        self::assertSame('fallback', $decision['source']);
    }

    public function testFreeFromKeepsTheCoreZero(): void
    {
        $delivery = self::delivery();
        $order = (object)['total_price' => 6000.0];

        $decision = OrderPrice::decide(new DeliveryQuote(80.0, null, 'api', null), self::coreInfo($delivery, $order), $delivery, $order);

        self::assertSame(0, $decision['info']['delivery_price']);
    }

    public function testAnotherModulesPriceWins(): void
    {
        $delivery = self::delivery();
        $order = (object)['total_price' => 1200.0];

        $decision = OrderPrice::decide(new DeliveryQuote(80.0, null, 'api', null), ['delivery_price' => 0.0, 'separate_delivery' => 0], $delivery, $order);

        self::assertSame(0.0, $decision['info']['delivery_price']);
        self::assertSame('external', $decision['source']);
    }

    public function testNoQuoteKeepsTheCorePrice(): void
    {
        $delivery = self::delivery();
        $order = (object)['total_price' => 1200.0];

        self::assertSame(['info' => self::coreInfo($delivery, $order), 'source' => null], OrderPrice::decide(null, self::coreInfo($delivery, $order), $delivery, $order));
    }
}
