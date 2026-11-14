<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/MassWorld.php';

use Modules\Sviat\Ukrposhta\Support\MassWorld;
use Modules\Sviat\Ukrposhta\Support\MassWorldDraft;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassReport;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassShipments;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use PHPUnit\Framework\TestCase;

class MassShipmentsTest extends TestCase
{
    use MassWorld;

    protected function setUp(): void
    {
        $this->setUpWorld();
    }

    /** @return array<int, array{kind: string, key: ?string, value: ?string}> за id замовлення */
    private static function byOrder(MassReport $report): array
    {
        $rows = [];
        foreach ($report->rows as $row) {
            $rows[$row['orderId']] = ['kind' => $row['kind'], 'key' => $row['key'], 'value' => $row['value']];
        }

        return $rows;
    }

    public function testSkipsForeignAndExisting(): void
    {
        $this->foreign = [501];
        $this->createdRow(502, '0500000000017');
        $this->rows->reserve(503);
        $this->rows->reserve(504);
        $this->rows->markUncertain(504);
        $this->drafts[505] = 'sviat__ukrposhta__ship_err_no_point';
        $this->drafts[506] = MassWorldDraft::valid(['weight' => 40_000]);

        $report = $this->mass()->run(['501', '502', '503', '504', '505', '506']);

        self::assertSame([
            501 => ['kind' => 'skipped', 'key' => 'sviat__ukrposhta__ship_err_not_own', 'value' => null],
            502 => ['kind' => 'skipped', 'key' => 'sviat__ukrposhta__mass_has_shipment', 'value' => '0500000000017'],
            503 => ['kind' => 'skipped', 'key' => 'sviat__ukrposhta__mass_unsettled', 'value' => null],
            504 => ['kind' => 'skipped', 'key' => 'sviat__ukrposhta__mass_unsettled', 'value' => null],
            505 => ['kind' => 'skipped', 'key' => 'sviat__ukrposhta__ship_err_no_point', 'value' => null],
            506 => ['kind' => 'skipped', 'key' => 'sviat__ukrposhta__ship_err_weight', 'value' => null],
        ], self::byOrder($report));
        self::assertSame(0, $report->untouched);
        self::assertSame([], $this->transport->requests(), 'no order reached Ukrposhta');
        self::assertSame(PendingShipment::UNCERTAIN, $this->rows->forOrder(504)->state, 'the unsettled row is left as it was');
    }

    public function testCreatesTheValidOne(): void
    {
        $this->foreign = [601];
        $this->createdAnswer(602, '0500000000024');

        $report = $this->mass()->run([601, 602]);

        self::assertSame(['kind' => 'created', 'key' => null, 'value' => '0500000000024'], self::byOrder($report)[602]);
        self::assertSame(1, $report->created());
        self::assertSame('0500000000024', $this->rows->forOrder(602)->barcode);
    }

    public function testUkrposhtaRefusalIsReportedWithItsText(): void
    {
        $this->transport->json(200, ['id' => 11])->json(200, ['uuid' => 'sender-uuid-1'])
            ->json(200, ['id' => 22])->json(200, ['uuid' => 'recipient-uuid-1'])
            ->json(400, ['message' => 'Recipient phone is invalid']);

        $row = self::byOrder($this->mass()->run([701]))[701];

        self::assertSame('failed', $row['kind']);
        self::assertSame('sviat__ukrposhta__ship_err_phone', $row['key']);
        self::assertSame('Recipient phone is invalid', $row['value']);
        self::assertNull($this->rows->forOrder(701), 'nothing was created, the order stays free');
    }

    public function testUnexpectedFailureIsLoggedMaskedAndTheRestGoesOn(): void
    {
        $this->transport->push(new \RuntimeException('socket closed for ?token=cp-token-1234'));
        $this->createdAnswer(402, '0500000000024');

        $rows = self::byOrder($this->mass()->run(['401', '402']));

        self::assertSame(['kind' => 'failed', 'key' => 'sviat__ukrposhta__mass_failed_generic', 'value' => null], $rows[401]);
        self::assertSame('created', $rows[402]['kind']);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('RuntimeException', $this->logger->text());
        self::assertStringContainsString('401', $this->logger->text());
        self::assertStringNotContainsString('cp-token-1234', $this->logger->text());
    }

    public function testStopsAtBudget(): void
    {
        $this->foreign = [801, 802, 803, 804];
        // Початок, потім перевірка перед кожним замовленням: третє починалося б уже після бюджету.
        $this->ticks = [0.0, 0.0, 10.0, MassShipments::BUDGET_SECONDS + 1];

        $report = $this->mass()->run([801, 802, 803, 804]);

        self::assertSame([801, 802], array_column($report->rows, 'orderId'));
        self::assertSame(2, $report->untouched);
    }

    public function testLimitAndIdsFromPost(): void
    {
        $raw = ['0', '-3', 'abc', '1e3', ['x'], '12', 12, ...array_map(strval(...), range(901, 925))];
        $this->foreign = range(1, 1000);

        $report = $this->mass()->run($raw);

        self::assertSame([12, ...range(901, 919)], array_column($report->rows, 'orderId'));
        self::assertSame(6, $report->untouched);
    }
}
