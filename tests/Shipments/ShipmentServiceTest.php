<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryShipmentRows.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryShipmentRows;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use Okay\Modules\Sviat\Ukrposhta\Shipments\RecipientParty;
use Okay\Modules\Sviat\Ukrposhta\Shipments\SenderClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDetails;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentOutcome;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentService;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;
use PHPUnit\Framework\TestCase;

class ShipmentServiceTest extends TestCase
{
    private const int ORDER = 77;
    private const string BARCODE = '0500000000017';

    private FakeTransport $transport;
    private InMemoryShipmentRows $rows;
    private StateStorage $state;
    /** @var array<string, string> */
    private array $stored;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryShipmentRows();
        $this->state = new InMemoryState();
        $this->stored = [
            SenderSettings::TYPE => 'COMPANY',
            SenderSettings::NAME => 'ТОВ «Приклад»',
            SenderSettings::EDRPOU => '12345678',
            SenderSettings::PHONE => '+380670000001',
            SenderSettings::IBAN => 'UA213052990000000026001234567',
            SenderSettings::POSTCODE => '01001',
        ];
    }

    private function service(?Credentials $credentials = null): ShipmentService
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);
        $ecom = new EcomClient($this->transport, new FixedCredentials($credentials ?? new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));

        return new ShipmentService(
            $ecom,
            $this->rows,
            new SenderClient($ecom, $this->state, new SenderSettings($settings, $this->createStub(SenderOffices::class))),
            new RecipientParty($ecom),
            new AuthFlags($this->state),
            fn(): int => $this->rows->now,
        );
    }

    private static function draft(): ShipmentDraft
    {
        return new ShipmentDraft(
            'STANDARD', 1500, 30, 20, 15, 1060.0, true, 1060.0, true, true, 'RETURN', 'Одяг', true,
            new PersonName('Шевченко', 'Олена'), '380670000002', '23222', 'office', true,
        );
    }

    /** Відправник ще не закешований: адреса й клієнт відправника, потім адреса й клієнт одержувача. */
    private function partiesAnswer(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'sender-uuid-1'])
            ->json(200, ['id' => 22])->json(200, ['uuid' => 'recipient-uuid-1']);
    }

    /** @return array<string, mixed> */
    private static function shipment(array $over = []): array
    {
        return $over + [
            'uuid' => 'shipment-uuid-1',
            'barcode' => self::BARCODE,
            'sender' => ['uuid' => 'sender-uuid-1'],
            'externalId' => (string)self::ORDER,
            'deliveryPrice' => 55,
            'postPay' => 1060,
            'postPayDeliveryPrice' => 41.2,
            'lifecycle' => ['status' => 'CREATED'],
        ];
    }

    /** @return list<string> «METHOD шлях» без хоста й токена */
    private function calls(): array
    {
        return array_map(
            static fn($r): string => $r->method . ' ' . preg_replace('~^https://www\.ukrposhta\.ua/ecom/0\.0\.1/|[?&]token=[^&]*~', '', $r->url),
            $this->transport->requests(),
        );
    }

    private function postsToShipments(): int
    {
        return count(array_filter($this->calls(), static fn(string $c): bool => $c === 'POST shipments'));
    }

    public function testCreateStoresShipment(): void
    {
        $this->partiesAnswer();
        $this->transport->json(200, self::shipment(['deliveryDate' => '2030-03-20T00:00:00', 'description' => 'Не з відповіді']));

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertTrue($outcome->ok);
        self::assertSame(self::BARCODE, $outcome->barcode);
        $row = $this->rows->forOrder(self::ORDER);
        $details = ShipmentDetails::decode($row->details);
        self::assertSame('Одяг', $details['description'], 'what was sent, not what came back');
        self::assertSame('Шевченко', $details['last_name']);
        self::assertSame('23222', $details['postcode']);
        self::assertSame(1500, $details['weight_g']);
        self::assertSame('2030-03-20', $details['delivery_date']);
        self::assertSame(PendingShipment::CREATED, $row->state);
        self::assertSame('shipment-uuid-1', $row->uuid);
        self::assertSame('CREATED', $row->status);
        self::assertSame(55.0, $row->delivery_price);
        self::assertSame(1060.0, $row->cod_amount);
        self::assertSame(41.2, $row->cod_fee);
        self::assertTrue($row->paid_by_recipient);
        $body = json_decode($this->transport->requests()[4]->body, true);
        self::assertSame('77', $body['externalId']);
        self::assertSame('recipient-uuid-1', $body['recipient']['uuid']);
        self::assertSame(22, $body['recipientAddressId']);
    }

    /** Відкликаний токен видно в налаштуваннях і на замовленні, доки eCom знову не відповість. */
    public function testAuthRefusalFlagsEcomUntilNextSuccess(): void
    {
        $flags = new AuthFlags($this->state);
        $this->transport->json(401, ['message' => 'Unauthorized']);
        self::assertFalse($this->service()->create(self::ORDER, self::draft())->ok);
        self::assertSame('bad_token', $flags->reason(AuthFlags::ECOM), 'refused while creating the parties');

        $this->partiesAnswer();
        $this->transport->json(403, ['message' => 'Forbidden']);
        self::assertFalse($this->service()->create(self::ORDER, self::draft())->ok);
        self::assertSame('bad_bearer', $flags->reason(AuthFlags::ECOM), 'refused on POST shipments');

        $this->transport->json(200, ['id' => 22])->json(200, ['uuid' => 'recipient-uuid-1'])->json(200, self::shipment());
        self::assertTrue($this->service()->create(self::ORDER, self::draft())->ok);
        self::assertNull($flags->reason(AuthFlags::ECOM));

        $this->transport->json(401, ['message' => 'Unauthorized']);
        self::assertFalse($this->service()->attach(self::ORDER + 1, '0500000000025')->ok);
        self::assertSame('bad_token', $flags->reason(AuthFlags::ECOM), 'attach is an eCom call too');
    }

    public function testCreateWithoutKeysSaysKeysAreMissing(): void
    {
        $outcome = $this->service(new Credentials('ecom-aaaa', 'trk', '', false))->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__ukrposhta__ship_err_not_configured', $outcome->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
        self::assertNull($this->rows->forOrder(self::ORDER), 'nothing was sent, the reservation is released');
        self::assertSame([], new AuthFlags($this->state)->items());
    }

    public function testSecondCreateWhilePendingRefused(): void
    {
        $this->rows->reserve(self::ORDER);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__ukrposhta__ship_err_pending', $outcome->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
        self::assertSame(PendingShipment::PENDING, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testCreateRefusedWhenShipmentExists(): void
    {
        $this->rows->add(self::ORDER, ['uuid' => 'u', 'barcode' => self::BARCODE, 'status' => 'CREATED', 'delivery_price' => null, 'cod_amount' => null, 'cod_fee' => null]);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__ukrposhta__ship_err_already', $outcome->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    /** Review Focus 1: Укрпошта могла створити відправлення, на яке ми не дочекалися відповіді. */
    public function testTimeoutAfterSendLeavesUncertainAndNoSecondPost(): void
    {
        $this->partiesAnswer();
        $this->transport->push(new TransportException('Operation timed out after 30000 milliseconds'));
        $service = $this->service();

        $first = $service->create(self::ORDER, self::draft());

        self::assertFalse($first->ok);
        self::assertSame('sviat__ukrposhta__ship_err_uncertain', $first->issues[0]['text']);
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);

        $second = $service->create(self::ORDER, self::draft());

        self::assertFalse($second->ok);
        self::assertSame('sviat__ukrposhta__ship_err_uncertain', $second->issues[0]['text']);
        self::assertSame(1, $this->postsToShipments());
        self::assertCount(5, $this->transport->requests());
    }

    public function testServerErrorAfterSendLeavesUncertain(): void
    {
        $this->partiesAnswer();
        $this->transport->json(502, ['message' => 'Bad gateway']);

        $this->service()->create(self::ORDER, self::draft());

        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testAnswerWithoutBarcodeLeavesUncertain(): void
    {
        $this->partiesAnswer();
        $this->transport->json(200, ['uuid' => 'shipment-uuid-1']);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    /** Процес помер між резервуванням і відповіддю: старий pending — так само невідомий, повтору немає. */
    public function testStalePendingIsTreatedAsUncertain(): void
    {
        $this->rows->reserve(self::ORDER);
        $this->rows->now += PendingShipment::STALE_SECONDS;

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__ukrposhta__ship_err_uncertain', $outcome->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    public function testFourHundredReleasesReservation(): void
    {
        $this->partiesAnswer();
        $this->transport->json(400, ['message' => 'invalid onFailReceiveType']);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertSame('on_fail', $outcome->issues[0]['field']);
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testFailureBeforeSendReleasesReservation(): void
    {
        $this->transport->push(new TransportException('Could not resolve host'));

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertSame(0, $this->postsToShipments());
    }

    public function testInvalidSenderPhoneReleasesReservation(): void
    {
        $this->stored[SenderSettings::PHONE] = '12345';

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__ukrposhta__ship_err_sender_phone', $outcome->issues[0]['text']);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertSame([], $this->transport->requests());
    }

    public function testInvalidDraftSendsNothing(): void
    {
        $draft = new ShipmentDraft(
            'STANDARD', 0, 30, 20, 15, 1060.0, false, 0.0, true, true, 'RETURN', 'Одяг', true,
            new PersonName('Шевченко', 'Олена'), '380670000002', '23222', 'office', false,
        );

        $outcome = $this->service()->create(self::ORDER, $draft);

        self::assertSame('weight', $outcome->issues[0]['field']);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertSame([], $this->transport->requests());
    }

    public function testNotSavedAfterCreationStaysUncertain(): void
    {
        $this->partiesAnswer();
        $this->transport->json(200, self::shipment());
        $this->rows->failComplete = true;

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__ukrposhta__ship_err_not_saved', $outcome->issues[0]['text']);
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    private function uncertainOrder(): void
    {
        $this->partiesAnswer();
        $this->transport->push(new TransportException('timeout'));
        $this->service()->create(self::ORDER, self::draft());
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testLookupFindsByExternalId(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, [
            self::shipment(['uuid' => 'other-uuid', 'barcode' => '0500000000025', 'externalId' => '76']),
            self::shipment(['type' => 'STANDARD']),
        ]);

        $outcome = $this->service()->lookup(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame(self::BARCODE, $outcome->barcode);
        self::assertSame('GET shipments?senderUuid=sender-uuid-1', $this->calls()[5]);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(PendingShipment::CREATED, $row->state);
        self::assertSame('shipment-uuid-1', $row->uuid);
        self::assertSame(['type' => 'STANDARD'], ShipmentDetails::decode($row->details));
    }

    /** Повтор після обриву міг дати два відправлення з тим самим externalId: потрібне останнє створене. */
    public function testLookupPrefersTheNewestMatch(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, [
            self::shipment(['uuid' => 'older-uuid', 'barcode' => '0500000000025']),
            self::shipment(['uuid' => 'other-order', 'barcode' => '0500000000032', 'externalId' => '76']),
            self::shipment(),
        ]);

        self::assertSame(self::BARCODE, $this->service()->lookup(self::ORDER)->barcode);
        self::assertSame('shipment-uuid-1', $this->rows->forOrder(self::ORDER)->uuid);
    }

    /** Відповідь без платника: ярлик сховає ціну лише тоді, коли відомо, що платить відправник. */
    public function testLookupWithoutPayerFallsBackToTheOrdersPayer(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, [self::shipment()]);

        self::assertTrue($this->service()->lookup(self::ORDER, false)->ok);
        self::assertFalse($this->rows->forOrder(self::ORDER)->paid_by_recipient);

        $this->rows->remove(self::ORDER);
        $this->transport->json(200, ['id' => 22])->json(200, ['uuid' => 'recipient-uuid-1'])->push(new TransportException('timeout'));
        $this->service()->create(self::ORDER, self::draft());
        $this->transport->json(200, [self::shipment(['paidByRecipient' => true])]);

        self::assertTrue($this->service()->lookup(self::ORDER, false)->ok);
        self::assertTrue($this->rows->forOrder(self::ORDER)->paid_by_recipient, 'the answer wins over the fallback');
    }

    public function testLookupReadsWrappedAnswer(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, ['content' => [self::shipment()], 'totalPages' => 1]);

        self::assertTrue($this->service()->lookup(self::ORDER)->ok);
    }

    public function testLookupNotFoundStaysUncertain(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, [self::shipment(['externalId' => '76'])]);

        $outcome = $this->service()->lookup(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__ukrposhta__ship_err_lookup_none', $outcome->issues[0]['text']);
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testLookupOnlyForUncertain(): void
    {
        $this->rows->reserve(self::ORDER);

        self::assertSame('sviat__ukrposhta__ship_err_nothing_to_check', $this->service()->lookup(self::ORDER)->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    private function cachedSender(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'sender-uuid-1']);
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);
        $ecom = new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));
        new SenderClient($ecom, $this->state, new SenderSettings($settings, $this->createStub(SenderOffices::class)))->uuid();
    }

    public function testAttachStoresOwnShipment(): void
    {
        $this->cachedSender();
        $this->transport->json(200, self::shipment(['description' => 'Книги', 'recipient' => ['lastName' => 'Франко', 'firstName' => 'Іван', 'phoneNumber' => '380501112233']]));

        $outcome = $this->service()->attach(self::ORDER, ' 05000 0000 0017 ');

        self::assertTrue($outcome->ok);
        self::assertSame(
            ['description' => 'Книги', 'last_name' => 'Франко', 'first_name' => 'Іван', 'phone' => '380501112233'],
            ShipmentDetails::decode($this->rows->forOrder(self::ORDER)->details),
        );
        self::assertSame('GET shipments/barcode/' . self::BARCODE, $this->calls()[2]);
        self::assertSame('shipment-uuid-1', $this->rows->forOrder(self::ORDER)->uuid);
        self::assertSame(PendingShipment::CREATED, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testAttachResolvesUncertain(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, self::shipment());

        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);
        self::assertSame(PendingShipment::CREATED, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testAttachRefusesForeignSender(): void
    {
        $this->cachedSender();
        $this->transport->json(200, self::shipment(['sender' => ['uuid' => 'someone-else']]));

        $outcome = $this->service()->attach(self::ORDER, self::BARCODE);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__ukrposhta__ship_err_foreign_sender', $outcome->issues[0]['text']);
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testAttachRefusesBarcodeOfAnotherOrder(): void
    {
        $this->rows->add(76, ['uuid' => 'u', 'barcode' => self::BARCODE, 'status' => 'CREATED', 'delivery_price' => null, 'cod_amount' => null, 'cod_fee' => null]);

        $outcome = $this->service()->attach(self::ORDER, self::BARCODE);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__ukrposhta__ship_err_barcode_elsewhere', $outcome->issues[0]['text']);
        self::assertSame('76', $outcome->value);
        self::assertSame([], $this->transport->requests());
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testAttachRefusesBadBarcode(): void
    {
        self::assertSame('sviat__ukrposhta__ship_err_barcode', $this->service()->attach(self::ORDER, 'RR12345')->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    public function testAttachUnknownBarcode(): void
    {
        $this->transport->json(404, ['message' => 'Shipment not found']);

        self::assertSame('sviat__ukrposhta__ship_err_barcode_not_found', $this->service()->attach(self::ORDER, self::BARCODE)->issues[0]['text']);
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    private function created(?string $status, ?int $eventCode = null): void
    {
        $this->rows->add(self::ORDER, ['uuid' => 'shipment-uuid-1', 'barcode' => self::BARCODE, 'status' => $status, 'delivery_price' => null, 'cod_amount' => null, 'cod_fee' => null]);
        $this->rows->rows[self::ORDER]->event_code = $eventCode;
    }

    public function testRemoveOnlyWhenCreated(): void
    {
        $this->created('REGISTERED');

        $refused = $this->service()->remove(self::ORDER);

        self::assertSame('sviat__ukrposhta__ship_err_not_deletable', $refused->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
        self::assertNotNull($this->rows->forOrder(self::ORDER));

        $this->rows->rows[self::ORDER]->status = 'CREATED';
        $this->rows->rows[self::ORDER]->status_group = 'created';
        $this->transport->push(new \Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse(200, ''));

        self::assertTrue($this->service()->remove(self::ORDER)->ok);
        self::assertSame(['DELETE shipments/shipment-uuid-1'], $this->calls());
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testRemoveUnknownStatusOnlyWhenTrackingSawNothing(): void
    {
        $this->created(null);
        $this->rows->saveEvent(self::ORDER, ['event_code' => 10100, 'event_name' => 'Прийнято', 'event_at' => '2030-03-18 10:00:00', 'event_reason' => null], '2030-03-18 10:05:00');
        self::assertSame('sviat__ukrposhta__ship_err_not_deletable', $this->service()->remove(self::ORDER)->issues[0]['text']);

        $this->rows->rows[self::ORDER]->event_code = null;
        $this->rows->rows[self::ORDER]->status_group = null;
        $this->transport->push(new \Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse(200, ''));
        self::assertTrue($this->service()->remove(self::ORDER)->ok);
    }

    public function testRemoveRefusedOnceTrackingSawTheParcelMove(): void
    {
        $this->created('CREATED');
        $this->rows->saveEvent(self::ORDER, ['event_code' => 20700, 'event_name' => 'У дорозі', 'event_at' => '2030-03-18 10:00:00', 'event_reason' => null], '2030-03-18 10:05:00');

        self::assertSame('in_transit', $this->rows->forOrder(self::ORDER)->status_group);
        self::assertSame('sviat__ukrposhta__ship_err_not_deletable', $this->service()->remove(self::ORDER)->issues[0]['text']);
        self::assertSame([], $this->transport->requests());
    }

    public function testRemoveKeepsRowWhenUkrposhtaRefuses(): void
    {
        $this->created('CREATED');
        $this->transport->json(400, ['message' => 'Shipment can not be deleted']);

        self::assertFalse($this->service()->remove(self::ORDER)->ok);
        self::assertNotNull($this->rows->forOrder(self::ORDER));
    }

    public function testUnlinkDropsRowWithoutApi(): void
    {
        $this->created('DELIVERING');

        self::assertTrue($this->service()->unlink(self::ORDER)->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertSame([], $this->transport->requests());
    }

    public function testUnlinkFreesUncertainButNotRunningCreation(): void
    {
        $this->rows->reserve(self::ORDER);
        self::assertSame('sviat__ukrposhta__ship_err_pending', $this->service()->unlink(self::ORDER)->issues[0]['text']);

        $this->rows->markUncertain(self::ORDER);
        self::assertTrue($this->service()->unlink(self::ORDER)->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testUnexpectedFailureBeforeSendReleasesAndRethrows(): void
    {
        $this->state = new class implements StateStorage {
            public function get(string $name): ?string
            {
                throw new \RuntimeException('storage is down');
            }

            public function set(string $name, ?string $value): void
            {
            }
        };

        try {
            $this->service()->create(self::ORDER, self::draft());
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('storage is down', $e->getMessage());
        }
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testEncodingFailureOfShipmentBodyReleasesWithoutSending(): void
    {
        $this->partiesAnswer();
        $draft = new ShipmentDraft(
            'STANDARD', 1500, 30, 20, 15, NAN, false, 0.0, true, true, 'RETURN', 'Одяг', true,
            new PersonName('Шевченко', 'Олена'), '380670000002', '23222', 'office', true,
        );

        $outcome = $this->service()->create(self::ORDER, $draft);

        self::assertFalse($outcome->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertNotContains('POST shipments', $this->calls());
    }

    public function testUnexpectedFailureDuringSendLeavesUncertain(): void
    {
        $this->partiesAnswer();
        $this->transport->push(new \TypeError('unexpected'));

        try {
            $this->service()->create(self::ORDER, self::draft());
            self::fail('expected exception');
        } catch (\TypeError) {
        }
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
        self::assertSame('sender-uuid-1', $this->rows->forOrder(self::ORDER)->sender_uuid);
    }

    public function testBarcodeOfAnotherOrderAfterCreationStaysUncertain(): void
    {
        $this->rows->add(76, ['uuid' => 'u', 'barcode' => self::BARCODE, 'status' => 'CREATED', 'delivery_price' => null, 'cod_amount' => null, 'cod_fee' => null]);
        $this->partiesAnswer();
        $this->transport->json(200, self::shipment());

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__ukrposhta__ship_err_not_saved', $outcome->issues[0]['text']);
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    private function senderChanged(): void
    {
        $this->stored[SenderSettings::IBAN] = 'UA903052990000000026009999999';
    }

    /** Реквізити змінилися після невідомого створення: шукати треба серед відправлень старого клієнта, нового не створювати. */
    public function testLookupAfterSenderChangeUsesTheRowsSender(): void
    {
        $this->uncertainOrder();
        $this->senderChanged();
        $this->transport->json(200, [self::shipment()]);

        $outcome = $this->service()->lookup(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame('GET shipments?senderUuid=sender-uuid-1', $this->calls()[5]);
        self::assertCount(6, $this->transport->requests());
    }

    public function testAttachAfterSenderChangeAcceptsOwnOlderShipment(): void
    {
        $this->cachedSender();
        $this->senderChanged();
        $this->transport->json(200, self::shipment());

        self::assertTrue($this->service()->attach(self::ORDER, self::BARCODE)->ok);
        self::assertSame(['POST addresses', 'POST clients', 'GET shipments/barcode/' . self::BARCODE], $this->calls());
    }

    public function testLookupIgnoresForeignSenderItem(): void
    {
        $this->uncertainOrder();
        $this->transport->json(200, [self::shipment(['sender' => ['uuid' => 'someone-else']])]);

        self::assertSame('sviat__ukrposhta__ship_err_lookup_none', $this->service()->lookup(self::ORDER)->issues[0]['text']);
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testAttachRefusesShipmentOfAnotherOrder(): void
    {
        $this->cachedSender();
        $this->transport->json(200, self::shipment(['externalId' => '76']));

        $outcome = $this->service()->attach(self::ORDER, self::BARCODE);

        self::assertSame('sviat__ukrposhta__ship_err_other_order', $outcome->issues[0]['text']);
        self::assertSame('76', $outcome->value);
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testOutcomeOfOwnRefusal(): void
    {
        $outcome = ShipmentOutcome::refused('sviat__ukrposhta__ship_err_none');

        self::assertFalse($outcome->ok);
        self::assertSame(['field' => null, 'text' => 'sviat__ukrposhta__ship_err_none', 'advice' => null, 'details' => null], $outcome->issues[0]);
    }
}
