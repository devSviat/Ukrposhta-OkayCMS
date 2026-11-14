<?php

namespace Modules\Sviat\Ukrposhta\Dictionaries;

require_once __DIR__ . '/../Support/ClassifierFixtures.php';

use Modules\Sviat\Ukrposhta\Support\ClassifierFixtures;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeRow;
use PHPUnit\Framework\TestCase;

class OfficeRowTest extends TestCase
{
    public function testMobileOfficeIsExcluded(): void
    {
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['TYPE_ACRONYM' => 'ПВ'])));
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['TYPE_ACRONYM' => 'ПВПЗ'])));
    }

    public function testLockedOfficeIsExcluded(): void
    {
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['LOCK_CODE' => '1'])));
    }

    public function testSecurityOfficeIsExcluded(): void
    {
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['IS_SECURITY' => '1'])));
    }

    public function testRestrictedIsExcluded(): void
    {
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['RESTRICTED_ACCESS' => '1'])));
    }

    public function testUnknownTypeIsExcluded(): void
    {
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['TYPE_ACRONYM' => 'ВПЗ'])));
    }

    public function testLockerKind(): void
    {
        $row = OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['TYPE_ACRONYM' => 'П-т']));

        $this->assertSame(OfficeKind::Locker->value, $row['kind']);
    }

    public function testKinds(): void
    {
        foreach (['МВ', 'МВПЗ', 'СВ', 'СВПЗ', 'ВВ', 'ЦКД'] as $acronym) {
            $this->assertSame(OfficeKind::Office, OfficeKind::fromType($acronym, null), $acronym);
        }
        $this->assertSame(OfficeKind::Pudo, OfficeKind::fromType('PUDO', null));
        $this->assertSame(OfficeKind::Locker, OfficeKind::fromType('', 'П-т'));
        $this->assertNull(OfficeKind::fromType('', null));
    }

    public function testAliasesNormalised(): void
    {
        $classic = OfficeRow::fromClassifier(ClassifierFixtures::office(7, ['REGION_ID' => '270']));
        $entry = ClassifierFixtures::office(7, ['REGION_ID' => '270', 'ID' => '7', 'LATITUDE' => '49.2328', 'IS_AUTOMATED' => '1', 'POCITY_ID' => '100']);
        unset($entry['POSTOFFICE_ID'], $entry['LATTITUDE'], $entry['ISAUTOMATED'], $entry['CITY_ID']);

        $aliased = OfficeRow::fromClassifier($entry);

        $this->assertSame($classic, $aliased);
        $this->assertSame(7, $aliased['id']);
        $this->assertSame(49.2328, $aliased['lat']);
        $this->assertSame(1, $aliased['is_automated']);
        $this->assertSame(100, $aliased['city_id']);
        $this->assertSame(270, $aliased['region_id']);
        $this->assertSame('21007', $aliased['postcode']);
        $this->assertNull($aliased['schedule_json']);
        $this->assertSame(40, strlen($aliased['row_hash']));
    }

    public function testPostcodeIsRequired(): void
    {
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['POSTCODE' => ''])));
        $this->assertNull(OfficeRow::fromClassifier(ClassifierFixtures::office(1, ['POSTCODE' => '1234567'])));
    }

    public function testCityFromOffice(): void
    {
        $city = CityRow::fromOffice(ClassifierFixtures::office(1, ['REGION_ID' => '270', 'CITY_UA' => 'Вінниця', 'SHORTCITYTYPE_UA' => '', 'CITYTYPE_UA' => 'місто']));

        $this->assertSame(100, $city['id']);
        $this->assertSame(270, $city['region_id']);
        $this->assertSame('Вінниця', $city['name_ua']);
        $this->assertSame('місто', $city['type_ua']);
        $this->assertSame('Вінницький', $city['district_ua']);
        $this->assertSame('Вінницька', $city['region_ua']);
        $this->assertSame('вінниця', $city['search_text']);
        $this->assertSame(0, $city['points']);
        $this->assertSame(7, CityRow::fromOffice(ClassifierFixtures::office(1, ['REGION_ID' => '270']), 7)['points']);
        $this->assertNotSame($city['row_hash'], CityRow::fromOffice(ClassifierFixtures::office(1, ['REGION_ID' => '270', 'CITY_UA' => 'Вінниця', 'SHORTCITYTYPE_UA' => '', 'CITYTYPE_UA' => 'місто']), 7)['row_hash'], 'a changed count rewrites the row');
    }
}
