<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDescription;
use PHPUnit\Framework\TestCase;

class ShipmentDescriptionTest extends TestCase
{
    private static function purchase(string $name, int $amount = 1, string $variant = ''): object
    {
        return (object)['product_name' => $name, 'variant_name' => $variant, 'amount' => $amount];
    }

    public function testOneProductIsItsName(): void
    {
        self::assertSame(
            'Чаша 5L для тестової мультиварки, ART-0001',
            ShipmentDescription::products([self::purchase('Чаша 5L для тестової мультиварки, ART-0001')]),
        );
    }

    public function testSeveralProductsWithQuantityAndVariant(): void
    {
        self::assertSame(
            'Шланг до тестового пилососа, ART-0002 ×2; Тестовий фільтр (білий)',
            ShipmentDescription::products([
                self::purchase("Шланг  до тестового\tпилососа, ART-0002", 2),
                self::purchase('Тестовий фільтр', 1, 'білий'),
            ]),
        );
    }

    public function testLongListIsCutOnAWordWithEllipsis(): void
    {
        $name = 'Акумуляторна батарея для тестового пилососа Модель-1 серії Приклад, ART-0004';
        $text = ShipmentDescription::products(array_fill(0, 5, self::purchase($name)));

        self::assertLessThanOrEqual(ShipmentDescription::MAX_LENGTH, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
        self::assertStringStartsWith($name . '; ' . $name, $text);
        self::assertDoesNotMatchRegularExpression('/[;,\s]…$/u', $text, 'no dangling separator before the ellipsis');
        $full = implode('; ', array_fill(0, 5, $name));
        $body = mb_substr($text, 0, -1);
        self::assertStringStartsWith($body, $full);
        self::assertContains(mb_substr($full, mb_strlen($body), 1), [' ', ';', ','], 'cut on a whole word');
    }

    public function testOneWordLongerThanTheLimitIsCutHard(): void
    {
        $text = ShipmentDescription::products([self::purchase(str_repeat('Я', 400))]);

        self::assertSame(ShipmentDescription::MAX_LENGTH, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    public function testModes(): void
    {
        $purchases = [self::purchase('Мотор для тестової хлібопічки ART-0005')];

        self::assertSame('Мотор для тестової хлібопічки ART-0005', ShipmentDescription::forOrder(ShipmentDescription::PRODUCTS, 'Тестові товари', 77, $purchases));
        self::assertSame('Замовлення №77', ShipmentDescription::forOrder(ShipmentDescription::ORDER, 'Тестові товари', 77, $purchases));
        self::assertSame('Тестові товари', ShipmentDescription::forOrder(ShipmentDescription::TEXT, ' Тестові товари ', 77, $purchases));
    }

    public function testNothingToDescribeFallsBackToTheOrder(): void
    {
        self::assertSame('Замовлення №77', ShipmentDescription::forOrder(ShipmentDescription::PRODUCTS, '', 77, []));
        self::assertSame('Замовлення №77', ShipmentDescription::forOrder(ShipmentDescription::PRODUCTS, '', 77, [self::purchase('  ')]));
        self::assertSame('Замовлення №77', ShipmentDescription::forOrder(ShipmentDescription::TEXT, '', 77, []));
    }
}
