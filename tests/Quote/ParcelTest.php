<?php

namespace Modules\Sviat\Ukrposhta\Quote;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Quote\Parcel;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ParcelTest extends TestCase
{
    public function testUnknownWeightUsesDefault(): void
    {
        $parcel = Parcel::fromMetrics(new CartMetrics(0.0, true, 500.0), ShippingConfig::fromSettings(static fn(string $k): mixed => null));

        self::assertSame(1000, $parcel->weightGrams);
        self::assertSame([30, 20, 15], [$parcel->lengthCm, $parcel->widthCm, $parcel->heightCm]);
    }

    public function testKnownWeightIsInGramsAndBoxComesFromSettings(): void
    {
        $settings = [ShippingConfig::BOX_LENGTH => '40', ShippingConfig::BOX_WIDTH => '25.2', ShippingConfig::BOX_HEIGHT => '10', ShippingConfig::DEFAULT_WEIGHT => '2'];
        $parcel = Parcel::fromMetrics(new CartMetrics(0.3505, false, 500.0), ShippingConfig::fromSettings(static fn(string $k): mixed => $settings[$k] ?? null));

        self::assertSame(351, $parcel->weightGrams);
        self::assertSame([40, 26, 10], [$parcel->lengthCm, $parcel->widthCm, $parcel->heightCm]);
    }

    public function testConfigDefaultsAndClamps(): void
    {
        $config = ShippingConfig::fromSettings(static fn(string $k): mixed => match ($k) {
            ShippingConfig::TYPE => 'bogus',
            ShippingConfig::DISCOUNT => '150',
            ShippingConfig::DECLARE_TOTAL => '0',
            default => null,
        });

        self::assertSame('STANDARD', $config->type);
        self::assertSame(100.0, $config->discountPercent);
        self::assertFalse($config->declareCartTotal);
        self::assertTrue(ShippingConfig::fromSettings(static fn(string $k): mixed => null)->declareCartTotal);
        self::assertSame('EXPRESS', ShippingConfig::fromSettings(static fn(string $k): mixed => $k === ShippingConfig::TYPE ? 'express' : null)->type);
    }
}
