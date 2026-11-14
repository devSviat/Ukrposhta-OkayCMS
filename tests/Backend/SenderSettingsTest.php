<?php

namespace Modules\Sviat\Ukrposhta\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use PHPUnit\Framework\TestCase;

class SenderSettingsTest extends TestCase
{
    private const string IBAN = 'UA213052990000000026001234567';

    /** @var array<string, mixed> */
    private array $stored = [];

    private function sender(): SenderSettings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->stored[$name] ?? null);
        $settings->method('set')->willReturnCallback(function (string $name, $value): void {
            $this->stored[$name] = $value;
        });
        $offices = $this->createStub(SenderOffices::class);
        $offices->method('exists')->willReturnCallback(static fn(string $postcode): bool => $postcode === '01001');

        return new SenderSettings($settings, $offices);
    }

    private static function company(array $override = []): array
    {
        return $override + [
            'sender_type' => 'COMPANY',
            'sender_name' => 'ТОВ «Приклад»',
            'sender_edrpou' => '12345678',
            'sender_phone' => '+380 67 000 00 01',
            'sender_iban' => 'ua21 3052 9900 0000 0026 0012 3456 7',
            'sender_postcode' => '01001',
        ];
    }

    private static function fop(array $override = []): array
    {
        return $override + [
            'sender_type' => 'PRIVATE_ENTREPRENEUR',
            'sender_last_name' => 'Шевченко',
            'sender_first_name' => 'Тарас',
            'sender_tin' => '1234567890',
            'sender_phone' => '0670000001',
            'sender_iban' => self::IBAN,
            'sender_postcode' => '01001',
        ];
    }

    public function testValidCompanyHasNoErrors(): void
    {
        self::assertSame([], $this->sender()->validate(self::company()));
    }

    public function testCompanyNeedsEdrpou(): void
    {
        $sender = $this->sender();

        self::assertSame(['sender_edrpou' => 'sviat__ukrposhta__sender_error_edrpou'], $sender->validate(self::company(['sender_edrpou' => ''])));
        self::assertArrayHasKey('sender_edrpou', $sender->validate(self::company(['sender_edrpou' => '1234567'])));
        self::assertArrayHasKey('sender_edrpou', $sender->validate(self::company(['sender_edrpou' => '1234567890'])));
        self::assertArrayHasKey('sender_name', $sender->validate(self::company(['sender_name' => '  '])));
        // ФОП-поля компанії не потрібні.
        self::assertSame([], $sender->validate(self::company(['sender_tin' => 'x', 'sender_last_name' => ''])));
    }

    public function testFopNeedsTin(): void
    {
        $sender = $this->sender();

        self::assertSame([], $sender->validate(self::fop()));
        self::assertSame(['sender_tin' => 'sviat__ukrposhta__sender_error_tin'], $sender->validate(self::fop(['sender_tin' => '12345678'])));
        self::assertArrayHasKey('sender_tin', $sender->validate(self::fop(['sender_tin' => ''])));
        self::assertArrayHasKey('sender_last_name', $sender->validate(self::fop(['sender_last_name' => ''])));
        self::assertArrayHasKey('sender_first_name', $sender->validate(self::fop(['sender_first_name' => ''])));
        self::assertSame([], $sender->validate(self::fop(['sender_edrpou' => '', 'sender_name' => ''])));
    }

    public function testIbanFormat(): void
    {
        $sender = $this->sender();

        self::assertSame([], $sender->validate(self::company(['sender_iban' => self::IBAN])));
        foreach ([
            'too short' => 'UA21305299000000002600123456',
            'not Ukrainian' => 'PL213052990000000026001234567',
            'letters inside' => 'UA21305299000000002600123456X',
            'wrong checksum' => 'UA223052990000000026001234567',
            'empty' => '',
        ] as $case => $iban) {
            self::assertSame(['sender_iban' => 'sviat__ukrposhta__sender_error_iban'], $sender->validate(self::company(['sender_iban' => $iban])), $case);
        }
    }

    public function testPhoneAndPostcode(): void
    {
        $sender = $this->sender();

        self::assertArrayHasKey('sender_phone', $sender->validate(self::company(['sender_phone' => '+38067000000'])));
        self::assertArrayHasKey('sender_phone', $sender->validate(self::company(['sender_phone' => '+480670000001'])));
        self::assertSame(['sender_postcode' => 'sviat__ukrposhta__sender_error_postcode'], $sender->validate(self::company(['sender_postcode' => '1001'])));
        self::assertSame(['sender_postcode' => 'sviat__ukrposhta__sender_error_postcode_unknown'], $sender->validate(self::company(['sender_postcode' => '99999'])));
    }

    public function testPhoneWithLettersIsRejectedNotStripped(): void
    {
        $sender = $this->sender();

        self::assertSame(['sender_phone' => 'sviat__ukrposhta__sender_error_phone'], $sender->validate(self::company(['sender_phone' => '067abc0000001'])));
        self::assertSame(['sender_phone' => 'sviat__ukrposhta__sender_error_phone'], $sender->validate(self::company(['sender_phone' => '+380670000001 доб. 2'])));
        self::assertSame([], $sender->validate(self::company(['sender_phone' => '+38 (067) 000-00-01'])));
    }

    public function testDigitFieldsWithLettersAreRejected(): void
    {
        $sender = $this->sender();

        self::assertArrayHasKey('sender_edrpou', $sender->validate(self::company(['sender_edrpou' => '1234567о'])));
        self::assertArrayHasKey('sender_tin', $sender->validate(self::fop(['sender_tin' => '123456789O'])));
    }

    public function testNameLengthsFollowTheApi(): void
    {
        $sender = $this->sender();

        self::assertSame(['sender_name' => 'sviat__ukrposhta__sender_error_name'], $sender->validate(self::company(['sender_name' => 'Т'])));
        self::assertSame(['sender_name' => 'sviat__ukrposhta__sender_error_name'], $sender->validate(self::company(['sender_name' => str_repeat('Т', 61)])));
        self::assertSame([], $sender->validate(self::company(['sender_name' => str_repeat('Т', 60)])));
        self::assertSame(['sender_last_name' => 'sviat__ukrposhta__sender_error_last_name'], $sender->validate(self::fop(['sender_last_name' => 'Ш'])));
        self::assertSame(['sender_first_name' => 'sviat__ukrposhta__sender_error_first_name'], $sender->validate(self::fop(['sender_first_name' => str_repeat('Т', 251)])));
    }

    public function testUnknownTypeIsAnError(): void
    {
        self::assertArrayHasKey('sender_type', $this->sender()->validate(self::company(['sender_type' => 'PRIVATE'])));
    }

    public function testSaveStoresNormalizedValuesOfTheChosenTypeOnly(): void
    {
        $this->stored[SenderSettings::TIN] = '1111111111';

        self::assertSame([], $this->sender()->save(self::company(['sender_tin' => '2222222222'])));

        self::assertSame('COMPANY', $this->stored[SenderSettings::TYPE]);
        self::assertSame('+380670000001', $this->stored[SenderSettings::PHONE]);
        self::assertSame(self::IBAN, $this->stored[SenderSettings::IBAN]);
        self::assertSame('01001', $this->stored['sviat__ukrposhta__sender_postcode']);
        self::assertSame('1111111111', $this->stored[SenderSettings::TIN], 'the other type keeps its stored values');
    }

    public function testInvalidPostSavesNothing(): void
    {
        $errors = $this->sender()->save(self::company(['sender_edrpou' => '1']));

        self::assertNotSame([], $errors);
        self::assertSame([], $this->stored);
    }
}
