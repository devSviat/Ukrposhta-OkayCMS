<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

/** Перевірені місто й пункт для одного замовлення або нічого, коли доставка не цього модуля. */
final readonly class CartSelection
{
    public function __construct(
        public ?object $delivery,
        public ?object $city,
        public ?object $office,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function applies(): bool
    {
        return $this->city !== null && $this->office !== null;
    }
}
