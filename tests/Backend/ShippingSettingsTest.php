<?php

namespace Modules\Sviat\Ukrposhta\Backend;

require_once __DIR__ . '/../Support/SettingsPage.php';

use Modules\Sviat\Ukrposhta\Support\SettingsPage;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Backend\ShippingSettings;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentConfig;
use PHPUnit\Framework\TestCase;

class ShippingSettingsTest extends TestCase
{
    private static function post(array $override = []): array
    {
        return $override + [
            'shipment_type' => 'EXPRESS',
            'default_weight' => '1,5',
            'box_length' => '40',
            'box_width' => '30',
            'box_height' => '20',
            'discount' => '7.5',
            'payer' => 'sender',
            'cod_payer' => 'recipient',
            'on_fail' => 'PROCESS_AS_REFUSAL',
            'description_mode' => 'text',
            'description' => ' Тестові товари ',
            'sms' => '1',
            'label_size' => 'SIZE_A4',
        ];
    }

    public function testValidPostIsSaved(): void
    {
        $stored = [];
        $settings = $this->createStub(Settings::class);
        $settings->method('set')->willReturnCallback(function (string $name, $value) use (&$stored): void {
            $stored[$name] = $value;
        });

        self::assertSame([], new ShippingSettings($settings)->save(self::post()));
        self::assertSame(1.5, $stored[ShippingConfig::DEFAULT_WEIGHT]);
        self::assertSame(40, $stored[ShippingConfig::BOX_LENGTH]);
        self::assertSame(0, $stored[ShippingConfig::DECLARE_TOTAL]);
        self::assertSame('Тестові товари', $stored[ShipmentConfig::DESCRIPTION]);
        self::assertSame('text', $stored[ShipmentConfig::DESCRIPTION_MODE]);
        self::assertSame(1, $stored[ShipmentConfig::SMS]);
        self::assertSame(Labels::SIZE_A4, $stored[ShipmentConfig::LABEL_SIZE]);
    }

    public function testInvalidPostSavesNothing(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->expects(self::never())->method('set');

        $errors = new ShippingSettings($settings)->save(self::post(['default_weight' => 'abc', 'box_width' => '0', 'payer' => 'nobody']));

        self::assertSame([
            'default_weight' => 'sviat__ukrposhta__shipping_error_weight',
            'box_width' => 'sviat__ukrposhta__shipping_error_width',
            'payer' => 'sviat__ukrposhta__shipping_error_payer',
        ], $errors);
    }

    public function testFormWithErrorsShowsWhatWasTyped(): void
    {
        $html = SettingsPage::render([
            'up_shipping' => ShippingSettings::formFromPost(self::post(['default_weight' => 'abc', 'box_width' => '0'])),
            'up_shipping_errors' => ['sviat__ukrposhta__shipping_error_weight'],
        ]);

        self::assertMatchesRegularExpression('/name="default_weight"[^>]*value="abc"/', $html);
        self::assertMatchesRegularExpression('/name="box_width"[^>]*value="0"/', $html);
        self::assertMatchesRegularExpression('/name="discount"[^>]*value="7.5"/', $html);
        self::assertMatchesRegularExpression('/id="up_description"[^>]*required/', $html, 'own text is required in text mode');
        self::assertStringContainsString('value="EXPRESS" selected', $html);
        self::assertStringContainsString('value="PROCESS_AS_REFUSAL" selected', $html);
        self::assertMatchesRegularExpression('/name="sms" value="1" checked/', $html);
        self::assertDoesNotMatchRegularExpression('/name="declare_total" value="1" checked/', $html);
    }

    public function testLabelSizeIsOneOfTwo(): void
    {
        self::assertSame(['label_size' => 'sviat__ukrposhta__shipping_error_label_size'], ShippingSettings::parse(self::post(['label_size' => 'SIZE_A5']))['errors']);
        self::assertSame(Labels::SIZE_10X10, ShipmentConfig::fromSettings(static fn(): null => null)->labelSize, 'the default is the thermal 10x10 label');

        $html = SettingsPage::render(['up_shipping' => ShippingSettings::formFromPost(self::post())]);
        self::assertStringContainsString('<option value="SIZE_A4" selected>', $html);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: array<string, string>}> */
    public static function garbage(): iterable
    {
        yield 'letters after a number' => [['box_height' => '15то'], ['box_height' => 'sviat__ukrposhta__shipping_error_height']];
        yield 'exponent' => [['box_length' => '1e2'], ['box_length' => 'sviat__ukrposhta__shipping_error_length']];
        yield 'fraction of a centimetre' => [['box_width' => '20.5'], ['box_width' => 'sviat__ukrposhta__shipping_error_width']];
        yield 'zero side' => [['box_height' => '0'], ['box_height' => 'sviat__ukrposhta__shipping_error_height']];
        yield 'negative side' => [['box_height' => '-5'], ['box_height' => 'sviat__ukrposhta__shipping_error_height']];
        yield 'longest side above 120' => [['box_length' => '121'], ['box_length' => 'sviat__ukrposhta__shipping_error_length']];
        yield 'two sides above 70' => [['box_length' => '100', 'box_width' => '71'], ['box' => 'sviat__ukrposhta__shipping_error_box_sides']];
        yield 'weight above 30 kg' => [['default_weight' => '30.001'], ['default_weight' => 'sviat__ukrposhta__shipping_error_weight']];
        yield 'zero weight' => [['default_weight' => '0'], ['default_weight' => 'sviat__ukrposhta__shipping_error_weight']];
        yield 'weight with a unit' => [['default_weight' => '1,5кг'], ['default_weight' => 'sviat__ukrposhta__shipping_error_weight']];
        yield 'weight finer than a gram' => [['default_weight' => '1.0005'], ['default_weight' => 'sviat__ukrposhta__shipping_error_weight']];
        yield 'hex weight' => [['default_weight' => '0x1A'], ['default_weight' => 'sviat__ukrposhta__shipping_error_weight']];
        yield 'discount above 100' => [['discount' => '100.01'], ['discount' => 'sviat__ukrposhta__shipping_error_discount']];
        yield 'negative discount' => [['discount' => '-1'], ['discount' => 'sviat__ukrposhta__shipping_error_discount']];
        yield 'discount with percent sign' => [['discount' => '5%'], ['discount' => 'sviat__ukrposhta__shipping_error_discount']];
        yield 'unknown type' => [['shipment_type' => 'DOCUMENT'], ['shipment_type' => 'sviat__ukrposhta__shipping_error_type']];
        yield 'unknown cod payer' => [['cod_payer' => 'bank'], ['cod_payer' => 'sviat__ukrposhta__shipping_error_cod_payer']];
        yield 'unknown on fail' => [['on_fail' => 'KEEP'], ['on_fail' => 'sviat__ukrposhta__shipping_error_on_fail']];
        yield 'unknown description mode' => [['description_mode' => 'sku'], ['description_mode' => 'sviat__ukrposhta__shipping_error_description_mode']];
        yield 'own text empty' => [['description' => '   '], ['description' => 'sviat__ukrposhta__shipping_error_description']];
        yield 'own text too long' => [['description' => str_repeat('я', 256)], ['description' => 'sviat__ukrposhta__shipping_error_description']];
        yield 'array instead of text' => [['box_length' => ['40']], ['box_length' => 'sviat__ukrposhta__shipping_error_length']];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('garbage')]
    public function testGarbageIsRejectedPerFieldAndNothingIsSaved(array $override, array $expected): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->expects(self::never())->method('set');

        self::assertSame($expected, new ShippingSettings($settings)->save(self::post($override)));
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $parsed = ShippingSettings::parse(self::post([
            'default_weight' => '30', 'box_length' => '70', 'box_width' => '120', 'box_height' => '1', 'discount' => '100',
        ]));

        self::assertSame([], $parsed['errors']);
        self::assertSame(30.0, $parsed['values'][ShippingConfig::DEFAULT_WEIGHT]);
        self::assertSame(120, $parsed['values'][ShippingConfig::BOX_WIDTH]);
        self::assertSame([], ShippingSettings::parse(self::post(['default_weight' => '0,001', 'discount' => '']))['errors']);
    }

    public function testOwnTextIsOptionalForOtherModes(): void
    {
        $parsed = ShippingSettings::parse(self::post(['description_mode' => 'products', 'description' => '']));

        self::assertSame([], $parsed['errors']);
        self::assertSame('products', $parsed['values'][ShipmentConfig::DESCRIPTION_MODE]);
        self::assertSame('products', ShipmentConfig::fromSettings(static fn(): null => null)->descriptionMode, 'products is the default');
    }

    public function testFormInputsCarryClientLimits(): void
    {
        $html = SettingsPage::render();

        self::assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*name="default_weight"[^>]*min="0.001"[^>]*max="30"[^>]*step="0.001"/', $html);
        foreach (['box_length', 'box_width', 'box_height'] as $side) {
            self::assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*name="' . $side . '"[^>]*min="1"[^>]*max="120"[^>]*step="1"/', $html);
        }
        self::assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*name="discount"[^>]*min="0"[^>]*max="100"[^>]*step="0.01"/', $html);
        self::assertSame(0, preg_match('/<select(?![^>]*selectpicker)[^>]*>/', $html), 'every select on the page is a selectpicker');
        self::assertMatchesRegularExpression('/id="up_description"[^>]*hidden/', $html, 'own text field is hidden outside text mode');
        self::assertStringContainsString('pattern="[0-9]{8}"', $html);
        self::assertStringContainsString('pattern="[0-9]{10}"', $html);
    }
}
