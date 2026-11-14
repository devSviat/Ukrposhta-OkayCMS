<?php

namespace Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointChange;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPriceRule;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use PHPUnit\Framework\TestCase;

class OrderPointChangeTest extends TestCase
{
    private const int MODULE = 81;

    private static function delivery(): object
    {
        return (object)['id' => 6, 'module_id' => self::MODULE, 'free_from' => 1_000_000];
    }

    private static function groupRows(bool $lockersEnabled = true): array
    {
        return [
            (object)['id' => 1, 'name' => 'Відділення', 'enabled' => 1, 'position' => 1, 'kinds' => '["office"]'],
            (object)['id' => 2, 'name' => 'Поштомати', 'enabled' => (int)$lockersEnabled, 'position' => 2, 'kinds' => '["locker","pudo"]'],
        ];
    }

    private static function city(): object
    {
        return (object)['id' => 501, 'name_ua' => 'Лісове', 'type_ua' => 'село', 'district_ua' => 'Вінницький', 'region_ua' => 'Вінницька'];
    }

    private static function office(array $override = []): object
    {
        return (object)($override + ['id' => 9001, 'city_id' => 501, 'postcode' => '23222', 'kind' => 'office', 'name_ua' => 'Лісове', 'address_ua' => 'вул. Центральна, 3']);
    }

    private static function select(?object $office, bool $cod = false, float $weight = 2.0, bool $lockersEnabled = true, ?object $delivery = null, ?object $shipment = null): array
    {
        return OrderPointChange::select($delivery ?? self::delivery(), self::MODULE, self::city(), $office, self::groupRows($lockersEnabled), new CartMetrics($weight, false, 1000.0, 1000.0), $cod, $shipment);
    }

    /** Відправлення йде на пункт, з яким його створено: новий пункт замовлення з ним розійшовся б. */
    public function testAnyShipmentRowLocksThePoint(): void
    {
        self::assertSame('op_err_locked', self::select(self::office(), shipment: (object)['state' => PendingShipment::CREATED, 'barcode' => '0500012345678'])['error']);
        self::assertSame('op_err_locked_check', self::select(self::office(), shipment: (object)['state' => PendingShipment::UNCERTAIN])['error']);
        self::assertSame('op_err_locked_check', self::select(self::office(), shipment: (object)['state' => PendingShipment::PENDING])['error']);
        self::assertNull(self::select(self::office())['error']);
        self::assertFalse(OrderPointChange::locks(null));
    }

    public function testOfficeIsChosen(): void
    {
        $result = self::select(self::office());

        self::assertNull($result['error']);
        self::assertNull($result['warning']);
        self::assertSame(9001, $result['selection']->office->id);
    }

    /** Поштомат не видає посилку з післяплатою: менеджер не може обійти це так само, як і покупець. */
    public function testLockerRejectedForCodOrder(): void
    {
        $locker = self::office(['id' => 9002, 'kind' => 'locker', 'name_ua' => 'Поштомат 1']);

        $result = self::select($locker, cod: true);
        self::assertSame('op_err_cod_locker', $result['error']);
        self::assertNull($result['selection']);

        self::assertNull(self::select($locker)['error'], 'without COD the locker is fine');
    }

    public function testTooHeavyIsSavedWithWarning(): void
    {
        $result = self::select(self::office(), weight: 40.0);

        self::assertNull($result['error']);
        self::assertSame('op_warn_too_heavy', $result['warning']);
        self::assertSame(9001, $result['selection']->office->id);
    }

    public function testKindOfDisabledGroupIsRefused(): void
    {
        self::assertSame('op_err_office', self::select(self::office(['kind' => 'locker']), lockersEnabled: false)['error']);
    }

    public function testOfficeOfOtherCityIsRefused(): void
    {
        self::assertSame('op_err_office_city', self::select(self::office(['city_id' => 1]))['error']);
    }

    public function testForeignDeliveryIsRefused(): void
    {
        self::assertSame('order_point_foreign', self::select(self::office(), delivery: (object)['id' => 9, 'module_id' => 12])['error']);
    }

    public function testPaidOrderPriceNotChanged(): void
    {
        $order = (object)['total_price' => 1000.0, 'delivery_price' => 60.0, 'separate_delivery' => 1, 'paid' => 1];

        $decision = OrderPriceRule::decide($order, self::delivery(), 95.0, false);
        self::assertSame(['action' => 'show', 'price' => 95.0], $decision);
        self::assertNull(OrderPointChange::autoPrice($decision));

        $unpaid = OrderPriceRule::decide((object)(['paid' => 0] + (array)$order), self::delivery(), 95.0, false);
        self::assertSame(95.0, OrderPointChange::autoPrice($unpaid), 'control: an unpaid separate order is repriced');
    }

    public function testSnapshotPricingFollowsTheNewOffice(): void
    {
        $quote = new \Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryQuote(95.0, 21.5, 'api', null);

        self::assertSame(['cod' => 1, 'cod_fee' => 21.5], OrderPointChange::snapshotPricing($quote, true));
        self::assertSame(['cod' => 0, 'cod_fee' => null], OrderPointChange::snapshotPricing(null, false));
    }
}
