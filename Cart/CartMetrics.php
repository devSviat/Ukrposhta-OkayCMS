<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

/** Що кошик каже про посилку, наскільки це відомо. */
final readonly class CartMetrics
{
    public function __construct(
        /** Сума ваг товарів, у яких вона є: посилка важить щонайменше стільки. */
        public float $knownWeight,
        /** Деякі товари без ваги: справжня посилка може бути важчою. */
        public bool $weightUnknown,
        public float $total,
        /** Сума кошика в гривнях, для лімітів Укрпошти; null, коли валюти UAH немає. */
        public ?float $totalUah = null,
    ) {
    }

    public function inUah(?float $totalUah): self
    {
        return new self($this->knownWeight, $this->weightUnknown, $this->total, $totalUah);
    }

    /** @param object $cart Okay\Core\Cart::get() */
    public static function fromCart(object $cart): self
    {
        return self::fromPurchases((array)($cart->purchases ?? []), is_numeric($cart->total_price ?? null) ? (float)$cart->total_price : 0.0);
    }

    /** @param iterable<object> $purchases кожен з `amount` і `variant` (weight) */
    public static function fromPurchases(iterable $purchases, float $total): self
    {
        $weight = 0.0;
        $weightUnknown = false;
        foreach ($purchases as $purchase) {
            $amount = max(0, (int)($purchase->amount ?? 0));
            $unitWeight = $purchase->variant->weight ?? null;
            if (is_numeric($unitWeight) && (float)$unitWeight > 0) {
                $weight += (float)$unitWeight * $amount;
            } else {
                $weightUnknown = true;
            }
        }

        return new self($weight, $weightUnknown, $total);
    }
}
