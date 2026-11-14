<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Shipments\SenderClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssue;
use PHPUnit\Framework\TestCase;

class SenderClientTest extends TestCase
{
    private const string IBAN = 'UA213052990000000026001234567';

    private FakeTransport $transport;
    private InMemoryState $state;

    /** @var array<string, mixed> */
    private array $stored;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
        $this->stored = [
            SenderSettings::TYPE => 'COMPANY',
            SenderSettings::NAME => 'ТОВ «Приклад»',
            SenderSettings::EDRPOU => '12345678',
            SenderSettings::PHONE => '+380670000001',
            SenderSettings::IBAN => self::IBAN,
            SenderSettings::POSTCODE => '01001',
        ];
    }

    private function sender(): SenderClient
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);
        $ecom = new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));

        return new SenderClient($ecom, $this->state, new SenderSettings($settings, $this->createStub(SenderOffices::class)));
    }

    public function testCreatesCompanyOnceAndCaches(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'sender-uuid-1']);
        $sender = $this->sender();

        $this->assertSame('sender-uuid-1', $sender->uuid());
        $this->assertSame('sender-uuid-1', $sender->uuid());

        $requests = $this->transport->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('{"postcode":"01001"}', $requests[0]->body);
        $this->assertSame(
            '{"type":"COMPANY","name":"ТОВ «Приклад»","edrpou":"12345678","addressId":11,"phoneNumber":"380670000001","bankAccount":"' . self::IBAN . '"}',
            $requests[1]->body,
        );
    }

    public function testFopUsesTinAndNames(): void
    {
        $this->stored[SenderSettings::TYPE] = 'PRIVATE_ENTREPRENEUR';
        $this->stored[SenderSettings::LAST_NAME] = 'Шевченко';
        $this->stored[SenderSettings::FIRST_NAME] = 'Тарас';
        $this->stored[SenderSettings::TIN] = '1234567890';
        $this->transport->json(200, ['id' => 12])->json(200, ['uuid' => 'sender-uuid-2']);

        $this->assertSame('sender-uuid-2', $this->sender()->uuid());

        $this->assertSame(
            '{"type":"PRIVATE_ENTREPRENEUR","lastName":"Шевченко","firstName":"Тарас","tin":"1234567890","addressId":12,"phoneNumber":"380670000001","bankAccount":"' . self::IBAN . '"}',
            $this->transport->requests()[1]->body,
        );
    }

    public function testRecreatedWhenSenderSettingsChange(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'old-uuid'])
            ->json(200, ['id' => 12])->json(200, ['uuid' => 'new-uuid']);
        $sender = $this->sender();
        $this->assertSame('old-uuid', $sender->uuid());

        $this->stored[SenderSettings::TYPE] = 'PRIVATE_ENTREPRENEUR';
        $this->stored[SenderSettings::LAST_NAME] = 'Шевченко';
        $this->stored[SenderSettings::FIRST_NAME] = 'Тарас';
        $this->stored[SenderSettings::TIN] = '1234567890';
        $this->stored[SenderSettings::IBAN] = 'UA903052990000000026009999999';

        $this->assertSame('new-uuid', $sender->uuid());
        $this->assertCount(4, $this->transport->requests());
        $this->assertStringContainsString('UA903052990000000026009999999', $this->transport->requests()[3]->body);
        $this->assertSame('new-uuid', $sender->uuid());
        $this->assertCount(4, $this->transport->requests());
    }

    public function testKnownKeepsEarlierClientsNewestFirst(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'old-uuid'])
            ->json(200, ['id' => 11])->json(200, ['uuid' => 'new-uuid']);
        $sender = $this->sender();
        self::assertSame([], $sender->known());
        $sender->uuid();
        $this->stored[SenderSettings::IBAN] = 'UA903052990000000026009999999';
        $sender->uuid();

        self::assertSame(['new-uuid', 'old-uuid'], $sender->known());
        self::assertCount(4, $this->transport->requests());
    }

    public function testChangedIbanAloneRecreatesClient(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'old-uuid'])
            ->json(200, ['id' => 11])->json(200, ['uuid' => 'new-uuid']);
        $sender = $this->sender();
        $sender->uuid();

        $this->stored[SenderSettings::IBAN] = 'UA903052990000000026009999999';

        $this->assertSame('new-uuid', $sender->uuid());
    }

    public function testFailureCachesNothing(): void
    {
        $this->transport->json(200, ['id' => 11])->json(422, ['message' => 'bad edrpou']);
        $sender = $this->sender();

        try {
            $sender->uuid();
            $this->fail('expected exception');
        } catch (UkrposhtaApiException) {
        }

        $this->assertSame([], $this->state->values);
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'sender-uuid-3']);
        $this->assertSame('sender-uuid-3', $sender->uuid());
    }

    public function testAnswerWithoutUuidCachesNothing(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['other' => 1]);

        try {
            $this->sender()->uuid();
            $this->fail('expected exception');
        } catch (UkrposhtaApiException) {
        }

        $this->assertSame([], $this->state->values);
    }

    public function testInvalidSenderPhoneSendsNothing(): void
    {
        $this->stored[SenderSettings::PHONE] = '12345';

        try {
            $this->sender()->uuid();
            $this->fail('expected exception');
        } catch (ShipmentIssue $e) {
            $this->assertSame('sviat__ukrposhta__ship_err_sender_phone', $e->getMessage());
        }

        $this->assertSame([], $this->transport->requests());
        $this->assertSame([], $this->state->values);
    }
}
