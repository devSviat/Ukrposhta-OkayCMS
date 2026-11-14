<?php

namespace Modules\Sviat\Ukrposhta\Extenders;

require_once __DIR__ . '/../Support/OrderWorld.php';
require_once __DIR__ . '/../Support/InMemoryShipmentRows.php';
require_once __DIR__ . '/../Support/InMemoryState.php';

use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Modules\Sviat\Ukrposhta\Support\InMemoryShipmentRows;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Modules\Sviat\Ukrposhta\Support\OrderWorld;
use Okay\Core\BackendTranslations;
use Okay\Core\Cart;
use Okay\Core\Design;
use Okay\Core\FrontTranslations;
use Okay\Core\Money;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Ukrposhta\Cart\DictionaryReady;
use Okay\Modules\Sviat\Ukrposhta\Cart\PopularCities;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Extenders\BackendExtender;
use Okay\Modules\Sviat\Ukrposhta\Extenders\FrontExtender;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointChange;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\CartQuotes;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDrafts;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentView;
use PHPUnit\Framework\TestCase;

/**
 * Менеджер змінив доставку замовлення на спосіб іншого модуля: знімок Укрпошти лишається в базі,
 * але ні сторінка замовлення, ні листи, ні адмінка його не показують.
 */
class SwitchedDeliveryTest extends TestCase
{
    use OrderWorld;

    /** @var array<string, mixed> */
    private array $assigned = [];
    private InMemoryShipmentRows $shipments;

    protected function setUp(): void
    {
        $this->shipments = new InMemoryShipmentRows();
    }

    private function design(): Design
    {
        $design = self::createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value) use ($design) {
            $this->assigned[$name] = $value;

            return $design;
        });

        return $design;
    }

    private static function blank(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    private function front(): FrontExtender
    {
        $factory = $this->entityFactory();
        $settings = self::createStub(Settings::class);
        $settings->method('get')->willReturn('');

        return new FrontExtender(
            $this->design(),
            $settings,
            $this->module(),
            $factory,
            self::createStub(FrontTranslations::class),
            self::blank(CartSelectionValidator::class),
            new OrderDeliveryStore($factory),
            self::createStub(Cart::class),
            self::blank(CartQuotes::class),
            new CodPayments($factory),
            self::createStub(Money::class),
            self::blank(UahTotal::class),
            self::blank(PopularCities::class),
            self::blank(DictionaryReady::class),
            $this->lines($factory),
        );
    }

    private function backend(): BackendExtender
    {
        $factory = $this->entityFactory();

        return new BackendExtender(
            $this->design(),
            self::blank(Request::class),
            $factory,
            $this->module(),
            self::createStub(BackendTranslations::class),
            new OrderDeliveryStore($factory),
            self::blank(OrderRowsCleanup::class),
            $this->lines($factory),
            $this->shipments,
            // Чернетки без конструктора: тест, що дійшов би до форми нового відправлення, впаде.
            new ShipmentView(
                $this->lines($factory),
                $this->shipments,
                self::blank(ShipmentDrafts::class),
                new AuthFlags(new InMemoryState()),
                new Labels(self::blank(EcomClient::class), $this->shipments, self::createStub(Settings::class), new AuthFlags(new InMemoryState())),
            ),
            self::blank(OrdersMassAction::class),
            self::blank(Response::class),
        );
    }

    /** BackendOrdersHelper::findOrders — бейджі списку лише з рядків бази: EcomClient без конструктора впав би на будь-якому виклику. */
    public function testOrdersListBadgesComeFromStoredRows(): void
    {
        $this->shipments->add(11, ['uuid' => 'u-11', 'barcode' => '0500000000017', 'status' => 'CREATED', 'delivery_price' => null, 'cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => null]);
        $this->shipments->saveEvent(11, ['event_code' => 21700, 'event_name' => 'Надійшло у відділення', 'event_at' => '2026-11-14 10:00:00', 'event_reason' => null], '2026-11-14 10:05:00');
        $this->shipments->reserve(12);
        $orders = [11 => (object)['id' => 11], 12 => (object)['id' => 12], 13 => (object)['id' => 13]];

        $this->backend()->listOrders($orders);

        self::assertSame(['barcode' => '0500000000017', 'group' => 'arrived', 'statusKey' => 'sviat__ukrposhta__status_arrived', 'eventName' => 'Надійшло у відділення'], $orders[11]->up_shipment);
        self::assertSame('sviat__ukrposhta__list_unsettled', $orders[12]->up_shipment['statusKey']);
        self::assertNull($orders[12]->up_shipment['barcode']);
        self::assertObjectNotHasProperty('up_shipment', $orders[13]);
    }

    /** OrdersHelper::getOrderPaymentMethodsList — сторінка замовлення покупця. */
    public function testOrderPageHidesPointOfSwitchedDelivery(): void
    {
        $this->switchedOrder();
        $this->front()->assignOrderDelivery(null, $this->order);

        self::assertArrayHasKey('up_order_delivery', $this->assigned);
        self::assertNull($this->assigned['up_order_delivery']);
    }

    /** NotifyHelper::finalEmailOrderUser / finalEmailOrderAdmin — той самий extender із замовленням листа. */
    public function testEmailsHidePointOfSwitchedDelivery(): void
    {
        $this->switchedOrder();
        $front = $this->front();
        foreach (['user', 'admin'] as $email) {
            $this->assigned = [];
            $front->assignOrderDelivery(null, $this->order);
            self::assertArrayHasKey('up_order_delivery', $this->assigned, $email);
            self::assertNull($this->assigned['up_order_delivery'], $email);
        }
    }

    public function testControlOwnDeliveryStillShowsPoint(): void
    {
        $this->ownOrder();
        $this->front()->assignOrderDelivery(null, $this->order);
        self::assertSame('село Лісове', $this->assigned['up_order_delivery']['city']);

        $this->assigned = [];
        $this->shipments->add(77, ['uuid' => 'u-1', 'barcode' => '0500012345678', 'status' => 'CREATED', 'delivery_price' => 50.0, 'cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => true]);
        $this->backend()->assignOrderDelivery($this->order);
        self::assertSame('село Лісове', $this->assigned['up_order_delivery']['city']);
        self::assertTrue($this->assigned['up_order_point']['locked'], 'the shipment went to this point');
        self::assertSame('card', $this->assigned['up_shipment']['mode']);
    }

    public function testAdminHidesPointAndEditorOfSwitchedDelivery(): void
    {
        $this->switchedOrder();
        $this->backend()->assignOrderDelivery($this->order);

        self::assertArrayHasKey('up_order_delivery', $this->assigned);
        self::assertNull($this->assigned['up_order_delivery']);
        self::assertNull($this->assigned['up_order_point']);
        self::assertNull($this->assigned['up_shipment'], 'no shipment and not ours: nothing to show');
    }

    /** Редактор пункту: збережений пункт замовлення з чужою доставкою не переоцінюється й не змінюється. */
    public function testEditorRefusesSwitchedOrder(): void
    {
        $this->switchedOrder();
        $factory = $this->entityFactory();
        $change = new OrderPointChange(
            $factory,
            $this->module(),
            self::blank(\Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore::class),
            new OrderDeliveryStore($factory),
            self::blank(\Okay\Modules\Sviat\Ukrposhta\Orders\OrderParcel::class),
            self::blank(CartQuotes::class),
            new CodPayments($factory),
            $this->lines($factory),
            $this->shipments,
        );

        $applied = $change->applyPrice(77);
        self::assertFalse($applied->ok);
        self::assertSame('order_point_foreign', $applied->error);

        $saved = $change->save(77, ['city_id' => '501', 'office_id' => '9001']);
        self::assertFalse($saved->ok);
        self::assertSame('order_point_foreign', $saved->error);
        self::assertSame([], $this->orderUpdates);
    }
}
