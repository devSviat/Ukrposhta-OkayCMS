<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;

/**
 * Посилка, для якої рахується ціна: чого не кажуть товари, те доповнюють типові значення
 * з налаштувань, а габарити завжди беруться з типової коробки.
 */
final readonly class Parcel
{
    public function __construct(
        public int $weightGrams,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
    ) {
    }

    public static function fromMetrics(CartMetrics $cart, ShippingConfig $config): self
    {
        $kilograms = $cart->knownWeight > 0 ? $cart->knownWeight : $config->defaultWeightKg;

        return new self(max(1, (int)round($kilograms * 1000)), $config->lengthCm, $config->widthCm, $config->heightCm);
    }
}
