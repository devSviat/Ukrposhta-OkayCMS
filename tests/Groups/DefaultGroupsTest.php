<?php

namespace Modules\Sviat\Ukrposhta\Groups;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;
use Okay\Modules\Sviat\Ukrposhta\Groups\DefaultGroups;
use PHPUnit\Framework\TestCase;

class DefaultGroupsTest extends TestCase
{
    public function testEveryKindHasItsOwnGroup(): void
    {
        $kinds = array_merge(...array_column(DefaultGroups::rows(), 'kinds'));
        sort($kinds);
        $all = array_map(static fn(OfficeKind $kind): string => $kind->value, OfficeKind::cases());
        sort($all);

        $this->assertSame($all, $kinds);
        $this->assertSame([1, 2, 3], array_column(DefaultGroups::rows(), 'position'));
    }

    public function testNameFallsBackToUkrainian(): void
    {
        $row = DefaultGroups::rows()[1];

        $this->assertSame('Parcel lockers', DefaultGroups::name($row, 'en'));
        $this->assertSame('Поштомати', DefaultGroups::name($row, 'ua'));
        $this->assertSame('Поштомати', DefaultGroups::name($row, 'ru'));
        $this->assertSame(['uk', 'en'], array_keys($row['names']));
    }
}
