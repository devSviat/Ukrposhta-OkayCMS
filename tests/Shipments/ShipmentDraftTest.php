<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use PHPUnit\Framework\TestCase;

class ShipmentDraftTest extends TestCase
{
    private static function draft(array $over = []): ShipmentDraft
    {
        $o = $over + [
            'type' => 'STANDARD', 'weight' => 1500, 'length' => 30, 'width' => 20, 'height' => 15,
            'declared' => 1000.0, 'cod' => true, 'codAmount' => 1060.0, 'payer' => true, 'codPayer' => true,
            'onFail' => 'RETURN', 'description' => 'Одяг', 'sms' => true, 'phone' => '380670000001',
            'kind' => 'office', 'paymentCod' => true,
        ];

        return new ShipmentDraft(
            $o['type'], $o['weight'], $o['length'], $o['width'], $o['height'], $o['declared'], $o['cod'], $o['codAmount'],
            $o['payer'], $o['codPayer'], $o['onFail'], $o['description'], $o['sms'],
            new PersonName('Шевченко', 'Олена'), $o['phone'], '23222', $o['kind'], $o['paymentCod'],
        );
    }

    public function testRequestBodyForCodToOffice(): void
    {
        $draft = self::draft(['declared' => 1060.0]);

        self::assertSame([], $draft->issues());
        self::assertSame([
            'sender' => ['uuid' => 'sender-uuid'],
            'recipient' => ['uuid' => 'recipient-uuid'],
            'recipientAddressId' => 55,
            'deliveryType' => 'W2W',
            'type' => 'STANDARD',
            'parcels' => [['weight' => 1500, 'length' => 30, 'width' => 20, 'height' => 15, 'declaredPrice' => 1060.0]],
            'declaredPrice' => 1060.0,
            'postPay' => 1060.0,
            'transferPostPayToBankAccount' => true,
            'postPayPaidByRecipient' => true,
            'paidByRecipient' => true,
            'onFailReceiveType' => 'RETURN',
            'description' => 'Одяг',
            'sms' => true,
            'externalId' => '77',
        ], $draft->toRequest('sender-uuid', 'recipient-uuid', 55, 77));
    }

    public function testNoPostPayKeysWithoutCod(): void
    {
        $request = self::draft(['cod' => false, 'codAmount' => 0.0])->toRequest('s', 'r', 1, 7);

        self::assertArrayNotHasKey('postPay', $request);
        self::assertArrayNotHasKey('transferPostPayToBankAccount', $request);
        self::assertArrayHasKey('postPayPaidByRecipient', $request);
    }

    public function testDescriptionIsRequiredAndWithinTheApiLimit(): void
    {
        self::assertSame(['description' => 'sviat__ukrposhta__ship_err_description'], self::draft(['description' => '  ', 'declared' => 1060.0])->errors());
        self::assertSame(['description' => 'sviat__ukrposhta__ship_err_description'], self::draft(['description' => str_repeat('я', 256), 'declared' => 1060.0])->errors());
        self::assertSame([], self::draft(['description' => str_repeat('я', 255), 'declared' => 1060.0])->errors());
    }

    public function testCodToLockerIsRejected(): void
    {
        foreach (['locker', 'pudo'] as $kind) {
            self::assertContains('sviat__ukrposhta__ship_err_cod_locker', self::draft(['kind' => $kind, 'declared' => 1060.0])->issues(), $kind);
        }
        self::assertNotContains('sviat__ukrposhta__ship_err_cod_locker', self::draft(['declared' => 1060.0])->issues());
        self::assertSame([], self::draft(['kind' => 'locker', 'cod' => false, 'codAmount' => 0.0])->issues());
    }

    public function testCodRequiresCodPayment(): void
    {
        self::assertContains('sviat__ukrposhta__ship_err_cod_not_allowed', self::draft(['paymentCod' => false, 'declared' => 1060.0])->issues());
    }

    public function testCodAboveDeclaredRejected(): void
    {
        self::assertSame(['sviat__ukrposhta__ship_err_cod_above_declared'], self::draft(['declared' => 1000.0, 'codAmount' => 1000.01])->issues());
        self::assertSame([], self::draft(['declared' => 1000.0, 'codAmount' => 1000.0])->issues());
    }

    public function testLockerForcesSenderPays(): void
    {
        $draft = self::draft(['kind' => 'locker', 'cod' => false, 'codAmount' => 0.0, 'payer' => true]);

        self::assertFalse($draft->paidByRecipient);
        self::assertFalse($draft->toRequest('s', 'r', 1, 7)['paidByRecipient']);
        self::assertTrue(self::draft(['cod' => false, 'codAmount' => 0.0])->paidByRecipient);
    }

    public function testWeightAndSideLimits(): void
    {
        $weight = 'sviat__ukrposhta__ship_err_weight';
        $sides = 'sviat__ukrposhta__ship_err_sides';
        $ok = ['cod' => false, 'codAmount' => 0.0];

        self::assertSame([], self::draft($ok + ['weight' => 30000, 'length' => 120, 'width' => 70, 'height' => 70])->issues());
        self::assertSame([$weight], self::draft($ok + ['weight' => 30001])->issues());
        self::assertSame([$weight], self::draft($ok + ['weight' => 0])->issues());
        self::assertSame([$sides], self::draft($ok + ['length' => 121])->issues());
        self::assertSame([$sides], self::draft($ok + ['length' => 120, 'width' => 71])->issues());
        self::assertSame([$sides], self::draft($ok + ['height' => 0])->issues());
    }

    public function testPhoneNameAndEnumsAreChecked(): void
    {
        $ok = ['cod' => false, 'codAmount' => 0.0];

        self::assertSame(['sviat__ukrposhta__ship_err_phone'], self::draft($ok + ['phone' => '12345'])->issues());
        self::assertSame(['sviat__ukrposhta__ship_err_type'], self::draft($ok + ['type' => 'CARGO'])->issues());
        self::assertSame(['sviat__ukrposhta__ship_err_on_fail'], self::draft($ok + ['onFail' => 'RETURN_7_DAYS'])->issues());
    }

    public function testWithInputOverlaysFormAndReportsFieldErrors(): void
    {
        $draft = self::draft(['declared' => 1060.0]);

        $changed = $draft->withInput(['weight' => 2000.0, 'cod' => false, 'phone' => '0671234567', 'payer' => 'sender', 'sms' => false]);
        self::assertInstanceOf(ShipmentDraft::class, $changed);
        self::assertSame(2000, $changed->weightG);
        self::assertFalse($changed->cod);
        self::assertFalse($changed->paidByRecipient);
        self::assertSame('380671234567', $changed->phone);
        self::assertSame(30, $changed->lengthCm);

        self::assertSame(['weight' => 'sviat__ukrposhta__ship_err_weight'], $draft->withInput(['weight' => 'abc']));
        self::assertSame(['sides' => 'sviat__ukrposhta__ship_err_sides'], $draft->withInput(['length' => 200.0]));
    }
}
