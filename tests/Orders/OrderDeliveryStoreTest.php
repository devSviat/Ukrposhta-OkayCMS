<?php

namespace Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelection;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryView;
use PHPUnit\Framework\TestCase;

class OrderDeliveryStoreTest extends TestCase
{
    public function testRowCopiesNamesOfTheChoice(): void
    {
        $selection = new CartSelection(
            (object)['id' => 6],
            (object)['id' => 501, 'name_ua' => 'Лісове', 'type_ua' => 'село', 'district_ua' => 'Вінницький', 'region_ua' => 'Вінницька'],
            (object)['id' => 9001, 'city_id' => 501, 'postcode' => '23222', 'kind' => 'office', 'name_ua' => 'Лісове', 'address_ua' => 'вул. Центральна, 3'],
        );

        self::assertSame([
            'order_id' => 10,
            'delivery_id' => 6,
            'city_id' => 501,
            'city_name' => 'село Лісове, Вінницький, Вінницька',
            'office_id' => 9001,
            'office_postcode' => '23222',
            'office_kind' => 'office',
            'office_title' => 'Лісове',
            'office_address' => 'вул. Центральна, 3',
        ], OrderDeliveryStore::row(10, $selection));
    }

    /** Довідник оновлюється щотижня; замовлення показує те, що обрав покупець, а не нинішню назву пункту. */
    public function testSnapshotKeepsNamesAfterDictionaryChange(): void
    {
        $city = (object)['id' => 501, 'name_ua' => 'Лісове', 'type_ua' => 'село', 'district_ua' => 'Вінницький', 'region_ua' => 'Вінницька'];
        $office = (object)['id' => 9001, 'city_id' => 501, 'postcode' => '23222', 'kind' => 'office', 'name_ua' => 'Лісове', 'address_ua' => 'вул. Центральна, 3'];
        $row = (object)(OrderDeliveryStore::row(10, new CartSelection((object)['id' => 6], $city, $office)) + ['price_source' => 'api', 'cod_fee' => null]);

        $city->name_ua = 'Лісове-Нове';
        $office->name_ua = 'Лісове-Нове';
        $office->address_ua = 'вул. Нова, 1';

        $lines = OrderDeliveryView::lines($row, 60.0, false, true);
        self::assertSame('село Лісове, Вінницький, Вінницька', $lines['city']);
        self::assertSame('23222 Лісове, вул. Центральна, 3', $lines['point']);
        self::assertFalse($lines['priceUnconfirmed']);
    }

    public function testCodFeeOnlyForCodPayment(): void
    {
        $row = (object)['city_name' => 'місто Київ', 'office_postcode' => '01001', 'office_title' => 'Київ 1', 'office_address' => null, 'price_source' => 'fallback', 'cod_fee' => '21.50'];

        self::assertSame(21.5, OrderDeliveryView::lines($row, 0.0, true, false)['codFee']);
        self::assertNull(OrderDeliveryView::lines($row, 0.0, false, true)['codFee']);
        self::assertTrue(OrderDeliveryView::lines($row, 0.0, false, true)['priceUnconfirmed']);
        self::assertFalse(OrderDeliveryView::lines($row, 70.0, false, true)['priceUnconfirmed'], 'the manager set a price');
        self::assertSame('01001 Київ 1', OrderDeliveryView::lines($row, 0.0, false, true)['point']);
    }
}
