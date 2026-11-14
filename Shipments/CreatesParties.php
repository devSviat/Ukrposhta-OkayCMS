<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;

/** Ланцюжок Укрпошти для будь-якої сторони відправлення: адреса за індексом, потім клієнт на цю адресу. */
trait CreatesParties
{
    private readonly EcomClient $ecom;

    private function createAddress(string $postcode): int
    {
        $answer = $this->ecom->call('POST', 'addresses', ['postcode' => $postcode]);
        $id = $answer['id'] ?? null;

        return is_int($id) || (is_string($id) && ctype_digit($id))
            ? (int)$id
            : throw UkrposhtaApiException::of(ApiErrorKind::Malformed, 'POST addresses: answer has no address id');
    }

    /** @param array<string, mixed> $client */
    private function createClient(array $client): string
    {
        $uuid = $this->ecom->call('POST', 'clients', $client)['uuid'] ?? null;

        return is_string($uuid) && $uuid !== ''
            ? $uuid
            : throw UkrposhtaApiException::of(ApiErrorKind::Malformed, 'POST clients: answer has no client uuid');
    }
}
