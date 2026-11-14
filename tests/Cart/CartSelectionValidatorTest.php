<?php

namespace Modules\Sviat\Ukrposhta\Cart;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelection;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelectionValidator;
use PHPUnit\Framework\TestCase;

class CartSelectionValidatorTest extends TestCase
{
    private const int MODULE = 81;

    private static function delivery(array $override = []): object
    {
        return (object)($override + ['id' => 6, 'module_id' => self::MODULE]);
    }

    private static function city(int $id = 501): object
    {
        return (object)['id' => $id, 'name_ua' => 'Вінниця', 'type_ua' => 'місто', 'district_ua' => 'Вінницький', 'region_ua' => 'Вінницька'];
    }

    private static function office(array $override = []): object
    {
        return (object)($override + ['id' => 9001, 'city_id' => 501, 'postcode' => '21001', 'kind' => 'office', 'name_ua' => 'Вінниця 1', 'address_ua' => 'вул. Соборна, 1']);
    }

    private static function check(?object $delivery, ?object $city, ?object $office, bool $cod = false, ?CartMetrics $cart = null, bool $ready = true): CartSelection|string
    {
        return CartSelectionValidator::check($delivery, self::MODULE, $city, $office, $cod, $cart ?? new CartMetrics(2.0, false, 1000.0), $ready);
    }

    public function testValidChoice(): void
    {
        $selection = self::check(self::delivery(), self::city(), self::office());

        self::assertInstanceOf(CartSelection::class, $selection);
        self::assertTrue($selection->applies());
        self::assertSame(9001, $selection->office->id);
    }

    public function testForeignDeliveryIgnored(): void
    {
        self::assertFalse(self::check(self::delivery(['module_id' => 12]), self::city(), self::office())->applies());
        self::assertFalse(self::check(null, null, null)->applies());
        self::assertFalse(CartSelectionValidator::check(self::delivery(['module_id' => 0]), 0, null, null, false, new CartMetrics(1.0, false, 1.0))->applies(), 'module not resolved');
    }

    public function testMissingCity(): void
    {
        self::assertSame('sviat__ukrposhta__err_city', self::check(self::delivery(), null, self::office()));
    }

    public function testUnknownOfficeIdRejected(): void
    {
        self::assertSame('sviat__ukrposhta__err_office', self::check(self::delivery(), self::city(), null));
    }

    public function testOfficeFromAnotherCityRejected(): void
    {
        self::assertSame('sviat__ukrposhta__err_office_city', self::check(self::delivery(), self::city(777), self::office()));
    }

    /** Пересувне відділення чи службовий тип до таблиці не потрапляють, але рядок із невідомим видом теж не приймається. */
    public function testUnknownKindRejected(): void
    {
        self::assertSame('sviat__ukrposhta__err_office', self::check(self::delivery(), self::city(), self::office(['kind' => 'mobile'])));
    }

    public function testLockerWithCodRejected(): void
    {
        self::assertSame('sviat__ukrposhta__err_cod_locker', self::check(self::delivery(), self::city(), self::office(['kind' => 'locker']), true));
        self::assertSame('sviat__ukrposhta__err_cod_locker', self::check(self::delivery(), self::city(), self::office(['kind' => 'pudo']), true));
        self::assertInstanceOf(CartSelection::class, self::check(self::delivery(), self::city(), self::office(['kind' => 'locker'])), 'prepaid locker is fine');
    }

    public function testTooHeavyRejected(): void
    {
        self::assertSame('sviat__ukrposhta__err_too_heavy', self::check(self::delivery(), self::city(), self::office(), false, new CartMetrics(31.0, false, 1.0)));
    }

    /** Без довідників пункт неможливо обрати: замовлення не повинне піти без пункту видачі. */
    public function testDictionaryNotReadyRejected(): void
    {
        self::assertSame('sviat__ukrposhta__err_dictionary', self::check(self::delivery(), null, null, false, null, false));
        self::assertFalse(self::check(self::delivery(['module_id' => 12]), null, null, false, null, false)->applies(), 'other modules are not affected');
    }

    public function testPostedIdsAreReadOnlyForThisDelivery(): void
    {
        $post = ['ukrposhta_city_id' => [6 => '501', 7 => '9'], 'ukrposhta_office_id' => [6 => '9001']];

        self::assertSame([501, 9001], CartSelectionValidator::postedIds($post, 6));
        self::assertSame([9, 0], CartSelectionValidator::postedIds($post, 7));
        self::assertSame([0, 0], CartSelectionValidator::postedIds(['ukrposhta_city_id' => 'x', 'ukrposhta_office_id' => [6 => ['a']]], 6));
    }
}
