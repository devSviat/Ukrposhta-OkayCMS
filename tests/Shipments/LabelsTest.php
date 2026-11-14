<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryShipmentRows.php';
require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryShipmentRows;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssue;
use PHPUnit\Framework\TestCase;

class LabelsTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryShipmentRows $rows;
    private InMemoryState $state;
    /** @var array<string, mixed> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryShipmentRows();
        $this->state = new InMemoryState();
    }

    private function labels(): Labels
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);

        return new Labels(
            new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false))),
            $this->rows,
            $settings,
            new AuthFlags($this->state),
        );
    }

    private function shipment(int $orderId, string $barcode, ?bool $paidByRecipient = true): void
    {
        $this->rows->add($orderId, ['uuid' => "u-{$barcode}", 'barcode' => $barcode, 'status' => 'CREATED', 'delivery_price' => 50.0, 'cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => $paidByRecipient]);
    }

    public function testOneUsesBarcodeAndSize(): void
    {
        $this->shipment(7, '0501234567890');
        $this->transport->push(new HttpResponse(200, '%PDF-1.4 one'));

        $pdf = $this->labels()->one(7, Labels::SIZE_A4);

        self::assertSame('%PDF-1.4 one', $pdf);
        self::assertSame(
            'https://www.ukrposhta.ua/forms/ecom/0.0.1/shipments/0501234567890/sticker?size=SIZE_A4&token=cp-token-1234',
            $this->transport->requests()[0]->url,
        );
    }

    public function testOneRefusesOrderWithoutCreatedShipment(): void
    {
        $this->rows->reserve(8);

        foreach ([7, 8] as $orderId) {
            try {
                $this->labels()->one($orderId, Labels::SIZE_10X10);
                self::fail('expected refusal');
            } catch (ShipmentIssue $e) {
                self::assertSame('sviat__ukrposhta__label_err_no_shipment', $e->getMessage());
            }
        }
        self::assertSame([], $this->transport->requests());
    }

    public function testManySkipsOrdersWithoutShipment(): void
    {
        $this->shipment(1, 'B1');
        $this->shipment(3, 'B3');
        $this->rows->reserve(2);
        $this->transport->push(new HttpResponse(200, '%PDF-1.4 many'));

        $pdfs = $this->labels()->many([1, 2, 3, 4], Labels::SIZE_10X10);

        self::assertSame(['%PDF-1.4 many'], $pdfs);
        $request = $this->transport->requests()[0];
        self::assertSame('POST', $request->method);
        self::assertSame('{"B1":{},"B3":{}}', $request->body);
        self::assertStringContainsString('/shipments/stickers-by-barcodes?size=SIZE_10X10&', $request->url);
    }

    public function testManyRefusesWhenNothingToPrint(): void
    {
        $this->expectException(ShipmentIssue::class);
        $this->labels()->many([1, 2], Labels::SIZE_A4);
    }

    public function testManyRefusesMoreThanHundredOrders(): void
    {
        try {
            $this->labels()->many(range(1, 101), Labels::SIZE_A4);
            self::fail('expected refusal');
        } catch (ShipmentIssue $e) {
            self::assertSame('sviat__ukrposhta__label_err_too_many', $e->getMessage());
        }
        self::assertSame([], $this->transport->requests());
    }

    public function testHideDeliveryPriceFollowsTheShipmentNotTheSetting(): void
    {
        $this->shipment(7, 'B7', false);
        $this->shipment(8, 'B8', true);
        $this->stored['sviat__ukrposhta__payer'] = 'recipient';
        $this->transport->push(new HttpResponse(200, '%PDF-a'), new HttpResponse(200, '%PDF-b'));

        $this->labels()->one(7, Labels::SIZE_10X10);
        $this->labels()->one(8, Labels::SIZE_10X10);

        [$sender, $recipient] = $this->transport->requests();
        self::assertStringContainsString('hideDeliveryPrice=1', $sender->url);
        self::assertStringNotContainsString('hideDeliveryPrice', $recipient->url);
    }

    public function testPriceShownWhenPayerUnknown(): void
    {
        $this->shipment(7, 'B7', null);
        $this->stored['sviat__ukrposhta__payer'] = 'sender';
        $this->transport->push(new HttpResponse(200, '%PDF-a'));

        $this->labels()->one(7, Labels::SIZE_10X10);

        self::assertStringNotContainsString('hideDeliveryPrice', $this->transport->requests()[0]->url);
    }

    public function testMixedBatchIsSplitByPayer(): void
    {
        $this->shipment(1, 'B1', true);
        $this->shipment(2, 'B2', false);
        $this->shipment(3, 'B3', true);
        $this->transport->push(new HttpResponse(200, '%PDF-recipient'), new HttpResponse(200, '%PDF-sender'));

        $pdfs = $this->labels()->many([1, 2, 3], Labels::SIZE_10X10);

        self::assertSame(['%PDF-recipient', '%PDF-sender'], $pdfs);
        [$first, $second] = $this->transport->requests();
        self::assertSame('{"B1":{},"B3":{}}', $first->body);
        self::assertStringNotContainsString('hideDeliveryPrice', $first->url);
        self::assertSame('{"B2":{}}', $second->body);
        self::assertStringContainsString('hideDeliveryPrice=1', $second->url);
    }

    public function testDefaultSizeFromSettingWithFallback(): void
    {
        self::assertSame(Labels::SIZE_10X10, $this->labels()->defaultSize());
        $this->stored['sviat__ukrposhta__label_size'] = 'SIZE_A4';
        self::assertSame(Labels::SIZE_A4, $this->labels()->defaultSize());
        $this->stored['sviat__ukrposhta__label_size'] = 'junk';
        self::assertSame(Labels::SIZE_10X10, $this->labels()->defaultSize());
    }

    public function testAuthRefusalFlagsEcomAndPrintClearsIt(): void
    {
        $this->shipment(7, '0501234567890');
        $flags = new AuthFlags($this->state);
        $this->transport->json(401, ['message' => 'Unauthorized']);

        try {
            $this->labels()->one(7, Labels::SIZE_10X10);
            self::fail('expected the refusal');
        } catch (\Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException) {
        }
        self::assertSame('bad_token', $flags->reason(AuthFlags::ECOM));

        $this->transport->push(new HttpResponse(200, '%PDF-1.4 ok'));
        $this->labels()->many([7], Labels::SIZE_10X10);
        self::assertNull($flags->reason(AuthFlags::ECOM));
    }
}
