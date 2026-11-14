<?php

namespace Modules\Sviat\Ukrposhta\Cart;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Modules\Sviat\Ukrposhta\Cart\CitySearch;
use PHPUnit\Framework\TestCase;

class CitySearchTest extends TestCase
{
    private static function row(int $id, string $name, string $district, string $region): object
    {
        return (object)['id' => (string)$id, 'name_ua' => $name, 'type_ua' => 'с.', 'district_ua' => $district, 'region_ua' => $region];
    }

    public function testNamesakesShowDistrictAndRegion(): void
    {
        $found = CitySearch::present([
            self::row(11, 'Лісове', 'Бердичівський', 'Житомирська'),
            self::row(12, 'Лісове', 'Звягельський', 'Житомирська'),
        ]);

        self::assertSame([
            ['id' => 11, 'name' => 'Лісове', 'type' => 'с.', 'district' => 'Бердичівський', 'region' => 'Житомирська'],
            ['id' => 12, 'name' => 'Лісове', 'type' => 'с.', 'district' => 'Звягельський', 'region' => 'Житомирська'],
        ], $found, 'two rows, told apart by district; the choice is by id');
    }

    public function testMissingPartsBecomeEmptyStrings(): void
    {
        $row = (object)['id' => '5', 'name_ua' => 'Київ', 'type_ua' => null, 'district_ua' => null, 'region_ua' => null];

        self::assertSame([['id' => 5, 'name' => 'Київ', 'type' => '', 'district' => '', 'region' => '']], CitySearch::present([$row]));
    }

    public function testQueryIsNormalisedLikeSearchText(): void
    {
        self::assertSame(['лісове'], CitySearch::words('  ЛІСОВЕ '));
        self::assertSame(['мар\'їно'], CitySearch::words('Мар’їно'));
        self::assertSame([], CitySearch::words('місто'), 'a bare type matches everything');
        self::assertSame(['100\%'], CitySearch::words('100%'), 'LIKE wildcards are escaped');
    }

    private static function recordingDatabase(): Database
    {
        return new class extends Database {
            public array $statements = [];

            public function __construct()
            {
            }

            public function __destruct()
            {
            }

            public function query(\Aura\SqlQuery\QueryInterface $query, $debug = false)
            {
                $this->statements[] = $query->getStatement();

                return true;
            }

            public function results($field = null, $mapped = null)
            {
                return [];
            }
        };
    }

    /** Справжній Select без конструктора: AbstractQuery інакше бере Database з ServiceLocator, а в CI бази немає. */
    private static function queryFactory(): QueryFactory
    {
        return new class (new AuraQueryFactory('mysql')) extends QueryFactory {
            public function newSelect()
            {
                $select = new \ReflectionClass(Select::class)->newInstanceWithoutConstructor();
                new \ReflectionProperty(Select::class, 'queryObject')->setValue($select, new AuraQueryFactory('mysql')->newSelect());

                return $select;
            }
        };
    }

    public function testOneLetterQueryReturnsNothingWithoutQuerying(): void
    {
        $db = self::recordingDatabase();
        $search = new CitySearch($db, self::queryFactory());

        self::assertSame([], $search->find('к'));
        self::assertSame([], $search->find('місто'));
        self::assertSame([], $db->statements);
    }

    public function testOrderedByStoredPointsNotBySubquery(): void
    {
        $db = self::recordingDatabase();

        (new CitySearch($db, self::queryFactory()))->find('лісове');

        self::assertStringContainsString('`c`.`points` DESC', $db->statements[0]);
        self::assertStringNotContainsString('COUNT(', $db->statements[0]);
    }
}
