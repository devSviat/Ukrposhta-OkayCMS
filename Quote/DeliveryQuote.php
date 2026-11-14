<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

final readonly class DeliveryQuote
{
    public function __construct(
        public ?float $price,
        /** Комісія Укрпошти за переказ післяплати: не входить у ціну, її платить отримувач. */
        public ?float $codFee,
        /** api | none | fallback: none — ціну не запитували; fallback — запит не вдався. */
        public string $source,
        /** null | unreachable | rejected | no_sender | no_uah | busy */
        public ?string $problem,
    ) {
    }

    /**
     * Укрпошту запитали, і вона не дала ціни: з покупця не беруть плату за доставку, менеджер
     * повідомить її пізніше. "busy" сюди не належить: оформлення замовлення все одно рахує ціну.
     */
    public function unpriced(): bool
    {
        return $this->price === null && $this->source === 'fallback' && $this->problem !== 'busy';
    }
}
