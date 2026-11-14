<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    public function testUsesSeparateFieldsWhenLastNameFilled(): void
    {
        $name = PersonName::fromOrder((object)['name' => 'Тарас', 'last_name' => 'Шевченко']);

        $this->assertSame(['Шевченко', 'Тарас', ''], [$name->last, $name->first, $name->middle]);
        $this->assertNull($name->validate());
    }

    public function testSplitsSingleField(): void
    {
        $name = PersonName::fromOrder((object)['name' => "Шевченко  Тарас Григорович", 'last_name' => '']);

        $this->assertSame(['Шевченко', 'Тарас', 'Григорович'], [$name->last, $name->first, $name->middle]);
        $this->assertNull($name->validate());
    }

    public function testSingleFieldWithTwoWordsHasNoMiddleName(): void
    {
        $name = PersonName::fromOrder((object)['name' => 'Шевченко Тарас', 'last_name' => null]);

        $this->assertSame(['Шевченко', 'Тарас', ''], [$name->last, $name->first, $name->middle]);
    }

    public function testSingleWordLacksFirstName(): void
    {
        $name = PersonName::fromOrder((object)['name' => 'Тарас', 'last_name' => '']);

        $this->assertSame('sviat__ukrposhta__ship_err_name', $name->validate());
    }

    public function testRejectsDigits(): void
    {
        $name = PersonName::fromOrder((object)['name' => 'Тарас1', 'last_name' => 'Шевченко']);

        $this->assertSame('sviat__ukrposhta__ship_err_name', $name->validate());
    }

    public function testAcceptsApostropheAndHyphen(): void
    {
        $name = PersonName::fromOrder((object)['name' => "Дар'я", 'last_name' => 'Коваль-Петренко']);

        $this->assertNull($name->validate());
    }

    public function testAcceptsTypographicApostrophes(): void
    {
        foreach (["Дарʼя", 'Дар’я'] as $first) {
            $this->assertNull(PersonName::fromOrder((object)['name' => $first, 'last_name' => "О’Коннор"])->validate());
        }
    }

    public function testRejectsTooShortAndTooLongParts(): void
    {
        $short = PersonName::fromOrder((object)['name' => 'Т', 'last_name' => 'Шевченко']);
        $long = PersonName::fromOrder((object)['name' => str_repeat('Т', 251), 'last_name' => 'Шевченко']);

        $this->assertSame('sviat__ukrposhta__ship_err_name', $short->validate());
        $this->assertSame('sviat__ukrposhta__ship_err_name', $long->validate());
    }

    public function testRejectsInvalidMiddleName(): void
    {
        $name = new PersonName('Шевченко', 'Тарас', '9');

        $this->assertSame('sviat__ukrposhta__ship_err_name', $name->validate());
    }
}
