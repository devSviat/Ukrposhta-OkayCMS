<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use Okay\Modules\Sviat\Ukrposhta\Shipments\RecipientParty;
use PHPUnit\Framework\TestCase;

class RecipientPartyTest extends TestCase
{
    public function testIndividualWithAddressAndPhone(): void
    {
        $transport = new FakeTransport();
        $transport->json(200, ['id' => 777])->json(200, ['uuid' => 'client-uuid-1']);
        $ecom = new EcomClient($transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));

        $result = new RecipientParty($ecom)->create(new PersonName('Шевченко', 'Тарас', 'Григорович'), '380671234567', '01001');

        $this->assertSame(['client_uuid' => 'client-uuid-1', 'address_id' => 777], $result);
        [$address, $client] = $transport->requests();
        $this->assertSame('POST', $address->method);
        $this->assertSame('https://www.ukrposhta.ua/ecom/0.0.1/addresses?token=cp-token-1234', $address->url);
        $this->assertSame('{"postcode":"01001"}', $address->body);
        $this->assertSame('https://www.ukrposhta.ua/ecom/0.0.1/clients?token=cp-token-1234', $client->url);
        $this->assertSame(
            '{"type":"INDIVIDUAL","lastName":"Шевченко","firstName":"Тарас","middleName":"Григорович","phoneNumber":"380671234567","addressId":777}',
            $client->body,
        );
    }

    public function testOmitsEmptyMiddleName(): void
    {
        $transport = new FakeTransport();
        $transport->json(200, ['id' => 5])->json(200, ['uuid' => 'c']);
        $ecom = new EcomClient($transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));

        new RecipientParty($ecom)->create(new PersonName('Шевченко', 'Тарас', ''), '380671234567', '01001');

        $this->assertStringNotContainsString('middleName', $transport->requests()[1]->body);
    }

    public function testAnswerWithoutIdsIsMalformed(): void
    {
        $transport = new FakeTransport();
        $transport->json(200, ['unexpected' => 1]);
        $ecom = new EcomClient($transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));

        $this->expectException(UkrposhtaApiException::class);
        new RecipientParty($ecom)->create(new PersonName('Шевченко', 'Тарас', ''), '380671234567', '01001');
    }
}
