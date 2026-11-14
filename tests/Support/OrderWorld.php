<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;

/**
 * Для TestCase: замовлення, його знімок пункту й спосіб доставки — без бази.
 * Записи в замовлення лишаються в $orderUpdates.
 */
trait OrderWorld
{
    private const int MODULE = 81;
    private const int OWN_DELIVERY = 6;
    private const int FOREIGN_DELIVERY = 9;
    private const int COD_PAYMENT = 3;

    private object $order;
    private ?object $snapshot = null;
    /** @var list<array{0:int, 1:array}> */
    private array $orderUpdates = [];

    private function switchedOrder(): void
    {
        $this->order = self::order(self::FOREIGN_DELIVERY);
        $this->snapshot = self::snapshot();
    }

    private function ownOrder(): void
    {
        $this->order = self::order(self::OWN_DELIVERY);
        $this->snapshot = self::snapshot();
    }

    private static function order(int $deliveryId): object
    {
        return (object)['id' => 77, 'delivery_id' => $deliveryId, 'payment_method_id' => self::COD_PAYMENT, 'delivery_price' => 60.0, 'total_price' => 1060.0, 'separate_delivery' => 0, 'paid' => 0];
    }

    private static function snapshot(): object
    {
        return (object)['order_id' => 77, 'city_id' => 501, 'city_name' => 'село Лісове', 'office_id' => 9001, 'office_postcode' => '23222', 'office_title' => 'Лісове', 'office_address' => 'вул. Центральна, 3', 'price_source' => 'api', 'cod_fee' => 21.5];
    }

    private function entityFactory(): EntityFactory
    {
        $deliveries = self::createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturnCallback(static fn($id) => match ((int)$id) {
            self::OWN_DELIVERY => (object)['id' => self::OWN_DELIVERY, 'module_id' => self::MODULE, 'hide_front_delivery_price' => 0, 'free_from' => 0, 'separate_payment' => 0],
            self::FOREIGN_DELIVERY => (object)['id' => self::FOREIGN_DELIVERY, 'module_id' => 12, 'hide_front_delivery_price' => 0, 'free_from' => 0, 'separate_payment' => 0],
            default => false,
        });
        $snapshots = self::createStub(OrderDeliveryEntity::class);
        $snapshots->method('findOne')->willReturnCallback(fn() => $this->snapshot ?? false);
        $payments = self::createStub(PaymentsEntity::class);
        $payments->method('get')->willReturn((object)['id' => self::COD_PAYMENT, 'module' => 'null', CodPayments::FIELD => 1]);
        $orders = self::createStub(OrdersEntity::class);
        $orders->method('get')->willReturnCallback(fn() => $this->order);
        $orders->method('update')->willReturnCallback(function ($id, $data) {
            $this->orderUpdates[] = [(int)$id, (array)$data];

            return true;
        });

        $currencies = self::createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object)['sign' => 'грн']);

        $factory = self::createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            DeliveriesEntity::class => $deliveries,
            OrderDeliveryEntity::class => $snapshots,
            PaymentsEntity::class => $payments,
            OrdersEntity::class => $orders,
            CurrenciesEntity::class => $currencies,
        });

        return $factory;
    }

    private function module(): Module
    {
        $module = self::createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);

        return $module;
    }

    private function lines(?EntityFactory $factory = null): OrderDeliveryLines
    {
        $factory ??= $this->entityFactory();

        return new OrderDeliveryLines($factory, $this->module(), new OrderDeliveryStore($factory), new CodPayments($factory));
    }
}
