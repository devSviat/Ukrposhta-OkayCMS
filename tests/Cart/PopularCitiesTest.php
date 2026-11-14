<?php

namespace Modules\Sviat\Ukrposhta\Cart;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Cart\PopularCities;
use PHPUnit\Framework\TestCase;

class PopularCitiesTest extends TestCase
{
    public function testCleanKeepsOrderDropsDuplicatesAndUnknown(): void
    {
        self::assertSame([3, 1, 7], PopularCities::clean(['3', 1, '3', 'x', -5, 99, '7'], [1, 3, 7]));
    }

    public function testCleanCapsTheList(): void
    {
        $ids = range(1, 30);

        self::assertCount(PopularCities::MAX, PopularCities::clean($ids, $ids));
    }

    public function testStoredIdsRoundTrip(): void
    {
        $state = new InMemoryState();
        PopularCities::store($state, [5, 2]);

        self::assertSame([5, 2], PopularCities::stored($state));
        self::assertSame([], PopularCities::stored(new InMemoryState()));
    }

    public function testCorruptStateIsEmpty(): void
    {
        $state = new InMemoryState();
        $state->set('popular_cities', '{"not":"a list"}');

        self::assertSame([], PopularCities::stored($state));
    }

    public function testButtonCarriesNameAndType(): void
    {
        self::assertSame(['id' => 7, 'label' => 'Київ', 'type' => 'м.'], PopularCities::button(['id' => 7, 'name' => 'Київ', 'type' => 'м.']));
    }
}
