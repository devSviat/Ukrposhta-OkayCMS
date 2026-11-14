<?php

namespace Modules\Sviat\Ukrposhta\Support;

require_once __DIR__ . '/FakeTransport.php';
require_once __DIR__ . '/FixedCredentials.php';
require_once __DIR__ . '/InMemoryState.php';
require_once __DIR__ . '/InMemoryShipmentRows.php';
require_once __DIR__ . '/ArrayLogger.php';

use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassOrders;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassShipments;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use Okay\Modules\Sviat\Ukrposhta\Shipments\RecipientParty;
use Okay\Modules\Sviat\Ukrposhta\Shipments\SenderClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentService;

/** Масові дії списку замовлень на справжньому ShipmentService із підставними HTTP, рядками й замовленнями. */
trait MassWorld
{
    private FakeTransport $transport;
    private InMemoryShipmentRows $rows;
    private ArrayLogger $logger;
    /** @var list<int> замовлення з доставкою не цього модуля */
    private array $foreign = [];
    /** @var array<int, ShipmentDraft|string> чернетка замовлення; решта отримує draft() */
    private array $drafts = [];
    /** @var list<float> відповіді годинника по черзі; останнє значення повторюється */
    private array $ticks = [];

    private function setUpWorld(): void
    {
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryShipmentRows();
        $this->logger = new ArrayLogger();
    }

    private function ecom(): EcomClient
    {
        return new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', 'cp-token-1234', false)));
    }

    private function service(): ShipmentService
    {
        $stored = [
            SenderSettings::TYPE => 'COMPANY',
            SenderSettings::NAME => 'ТОВ «Приклад»',
            SenderSettings::EDRPOU => '12345678',
            SenderSettings::PHONE => '+380670000001',
            SenderSettings::IBAN => 'UA213052990000000026001234567',
            SenderSettings::POSTCODE => '01001',
        ];
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static fn(string $name) => $stored[$name] ?? null);
        $ecom = $this->ecom();

        return new ShipmentService(
            $ecom,
            $this->rows,
            new SenderClient($ecom, new InMemoryState(), new SenderSettings($settings, $this->createStub(SenderOffices::class))),
            new RecipientParty($ecom),
            new AuthFlags(new InMemoryState()),
            fn(): int => $this->rows->now,
        );
    }

    private function labels(): Labels
    {
        return new Labels($this->ecom(), $this->rows, $this->createStub(Settings::class), new AuthFlags(new InMemoryState()));
    }

    private function mass(): MassShipments
    {
        $orders = new class ($this->foreign, $this->drafts) implements MassOrders {
            /**
             * @param list<int> $foreign
             * @param array<int, ShipmentDraft|string> $drafts
             */
            public function __construct(private array $foreign, private array $drafts)
            {
            }

            public function isOurs(int $orderId): bool
            {
                return !in_array($orderId, $this->foreign, true);
            }

            public function draft(int $orderId): ShipmentDraft|string
            {
                return $this->drafts[$orderId] ?? MassWorldDraft::valid();
            }
        };
        $clock = $this->ticks === [] ? null : function (): float {
            return count($this->ticks) > 1 ? array_shift($this->ticks) : $this->ticks[0];
        };

        return new MassShipments($this->service(), $this->rows, $orders, $this->logger, $clock);
    }

    /** Відправник ще не закешований: адреса й клієнт відправника, потім адреса й клієнт одержувача, потім відправлення. */
    private function createdAnswer(int $orderId, string $barcode): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'sender-uuid-1'])
            ->json(200, ['id' => 22])->json(200, ['uuid' => 'recipient-uuid-1'])
            ->json(200, [
                'uuid' => 'shipment-uuid-' . $orderId,
                'barcode' => $barcode,
                'sender' => ['uuid' => 'sender-uuid-1'],
                'externalId' => (string)$orderId,
                'deliveryPrice' => 55,
                'lifecycle' => ['status' => 'CREATED'],
            ]);
    }

    private function createdRow(int $orderId, string $barcode, bool $paidByRecipient = true): void
    {
        $this->rows->add($orderId, [
            'uuid' => 'shipment-uuid-' . $orderId, 'barcode' => $barcode, 'status' => 'CREATED',
            'delivery_price' => 55.0, 'cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => $paidByRecipient,
        ]);
    }
}

final class MassWorldDraft
{
    public static function valid(array $over = []): ShipmentDraft
    {
        $v = $over + ['weight' => 1500];

        return new ShipmentDraft(
            'STANDARD', $v['weight'], 30, 20, 15, 1060.0, false, 0.0, true, true, 'RETURN', 'Одяг', true,
            new PersonName('Шевченко', 'Олена'), '380670000002', '23222', 'office', false,
        );
    }
}
