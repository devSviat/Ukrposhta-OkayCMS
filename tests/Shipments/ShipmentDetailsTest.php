<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDetails;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use PHPUnit\Framework\TestCase;

class ShipmentDetailsTest extends TestCase
{
    private static function draft(string $middle = 'Григорович', string $phone = '380671234567'): ShipmentDraft
    {
        return new ShipmentDraft(
            'STANDARD', 1250, 30, 20, 10, 1200.0, true, 1200.0, true, false, 'RETURN', 'Одяг', true,
            new PersonName('Шевченко', 'Тарас', $middle), $phone, '01001', 'office', true,
        );
    }

    /** Знімок має той самий номер, що пішов в Укрпошту: ShipmentService нормалізує його перед створенням одержувача. */
    public function testPhoneIsStoredAsSentToUkrposhta(): void
    {
        self::assertSame('380671234567', ShipmentDetails::fromDraft(self::draft(phone: '+38 (067) 123-45-67'), [])['phone']);
        self::assertSame('12345', ShipmentDetails::fromDraft(self::draft(phone: '12345'), [])['phone'], 'not a phone: kept as typed');
    }

    public function testDraftIsWhatWasSentPlusTheDeliveryDateOfTheAnswer(): void
    {
        $details = ShipmentDetails::fromDraft(self::draft(), ['uuid' => 'u', 'deliveryDate' => '2026-11-19T00:00:00', 'secret' => 'x']);

        self::assertSame([
            'type' => 'STANDARD', 'description' => 'Одяг', 'on_fail' => 'RETURN',
            'last_name' => 'Шевченко', 'first_name' => 'Тарас', 'middle_name' => 'Григорович', 'phone' => '380671234567', 'postcode' => '01001',
            'delivery_date' => '2026-11-19',
            'weight_g' => 1250, 'length' => 30, 'width' => 20, 'height' => 10, 'declared' => 1200.0, 'sms' => true,
        ], $details);
    }

    public function testApiAnswerGivesOnlyKnownFields(): void
    {
        $details = ShipmentDetails::fromApi([
            'type' => 'EXPRESS',
            'description' => 'Книги',
            'onFailReceiveType' => 'PROCESS_AS_REFUSAL',
            'sms' => false,
            'declaredPrice' => '450.5',
            'deliveryDate' => '2026-11-20',
            'parcels' => [['weight' => 900, 'length' => '25', 'width' => 15, 'height' => 5]],
            'recipient' => ['lastName' => 'Франко', 'firstName' => 'Іван', 'phoneNumber' => '380501112233', 'uuid' => 'r-1'],
            'sender' => ['uuid' => 's-1', 'name' => 'Магазин'],
        ]);

        self::assertSame([
            'type' => 'EXPRESS', 'description' => 'Книги', 'on_fail' => 'PROCESS_AS_REFUSAL',
            'last_name' => 'Франко', 'first_name' => 'Іван', 'phone' => '380501112233',
            'delivery_date' => '2026-11-20',
            'weight_g' => 900, 'length' => 25, 'width' => 15, 'height' => 5, 'declared' => 450.5, 'sms' => false,
        ], $details);
    }

    public function testWrongTypesAndBadDatesAreDropped(): void
    {
        $details = ShipmentDetails::fromApi([
            'type' => ['EXPRESS'],
            'description' => '   ',
            'declaredPrice' => 'багато',
            'deliveryDate' => '2026-02-31',
            'parcels' => 'x',
            'recipient' => ['lastName' => 7, 'phoneNumber' => null],
            'sms' => 'yes',
        ]);

        self::assertSame([], $details);
        self::assertSame([], ShipmentDetails::fromApi(['deliveryDate' => 'завтра', 'parcels' => [['weight' => -5]]]));
    }

    public function testTextIsCutToTheColumnLimits(): void
    {
        $details = ShipmentDetails::decode(ShipmentDetails::encode(ShipmentDetails::fromApi([
            'description' => str_repeat('О', 400),
            'recipient' => ['lastName' => str_repeat('Ш', 400), 'phoneNumber' => str_repeat('3', 40)],
        ])));

        self::assertSame(255, mb_strlen($details['description']));
        self::assertSame(250, mb_strlen($details['last_name']));
        self::assertSame(20, strlen($details['phone']));
    }

    public function testDecodeKeepsOnlyTheWhitelist(): void
    {
        self::assertSame([], ShipmentDetails::decode(null));
        self::assertSame([], ShipmentDetails::decode('not json'));
        self::assertSame([], ShipmentDetails::decode('[1, 2]'));
        self::assertSame(
            ['description' => 'Одяг', 'weight_g' => 1250],
            ShipmentDetails::decode('{"description": "Одяг", "weight_g": 1250, "token": "x", "sms": "1", "declared": -3}'),
        );
        self::assertNull(ShipmentDetails::encode([]));
    }
}
