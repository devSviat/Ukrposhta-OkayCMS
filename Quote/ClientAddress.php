<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

/** Адреса клієнта для лімітів: окремий клас, щоб розрахунок можна було перевірити без суперглобальних змінних. */
class ClientAddress
{
    public function address(): ?string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : null;
    }
}
