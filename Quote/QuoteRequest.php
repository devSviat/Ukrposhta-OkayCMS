<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

final readonly class QuoteRequest
{
    public function __construct(
        public ShippingConfig $config,
        public string $recipientPostcode,
        public Parcel $parcel,
        /** Сума кошика в гривнях: з неї беруться оголошена цінність і сума післяплати. */
        public float $totalUah,
        public bool $cod,
    ) {
    }
}
