<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;

/** Одержувач відправлення: завжди фізособа; клієнта створюємо для кожного відправлення, адресу Укрпошта змінити не дозволяє. */
final class RecipientParty
{
    use CreatesParties;

    public function __construct(EcomClient $ecom)
    {
        $this->ecom = $ecom;
    }

    /** @return array{client_uuid: string, address_id: int} */
    public function create(PersonName $name, string $phone, string $postcode): array
    {
        $addressId = $this->createAddress($postcode);
        $client = [
            'type' => 'INDIVIDUAL',
            'lastName' => $name->last,
            'firstName' => $name->first,
        ];
        if ($name->middle !== '') {
            $client['middleName'] = $name->middle;
        }

        return [
            'client_uuid' => $this->createClient($client + ['phoneNumber' => $phone, 'addressId' => $addressId]),
            'address_id' => $addressId,
        ];
    }
}
