<?php

namespace Modules\Sviat\Ukrposhta\Cart;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\OfficeList;
use PHPUnit\Framework\TestCase;

class OfficeListTest extends TestCase
{
    private static function groupRows(): array
    {
        return [
            (object)['id' => 1, 'name' => 'Відділення', 'enabled' => 1, 'position' => 1, 'kinds' => '["office"]'],
            (object)['id' => 2, 'name' => 'Поштомати', 'enabled' => 1, 'position' => 2, 'kinds' => '["locker"]'],
            (object)['id' => 3, 'name' => 'Вимкнена', 'enabled' => 0, 'position' => 3, 'kinds' => '["pudo"]'],
        ];
    }

    private static function office(int $id, string $postcode, string $kind, string $name = '', string $address = 'вул. Соборна, 1'): object
    {
        return (object)['id' => (string)$id, 'postcode' => $postcode, 'kind' => $kind, 'name_ua' => $name ?: "Відділення {$postcode}", 'address_ua' => $address];
    }

    private static function offices(): array
    {
        return [
            self::office(1, '21001', 'office', 'Вінниця 1', 'вул. Соборна, 1'),
            self::office(2, '21002', 'office', 'Вінниця 2', 'вул. Келецька, 5'),
            self::office(3, '21100', 'locker', 'Поштомат 21100', 'вул. Пирогова, 9'),
            self::office(4, '21101', 'pudo', 'Пункт 21101', 'вул. Пирогова, 10'),
        ];
    }

    private static function cart(): CartMetrics
    {
        return new CartMetrics(1.0, false, 100.0);
    }

    public function testGroupsCountPoints(): void
    {
        $result = OfficeList::build(self::offices(), self::groupRows(), self::cart(), false, null, '');

        self::assertSame([['id' => 1, 'name' => 'Відділення', 'count' => 2], ['id' => 2, 'name' => 'Поштомати', 'count' => 1]], $result['groups'], 'a disabled group and its pudo point are not offered');
        self::assertSame([1, 2], array_column($result['points'], 'id'), 'the first group is chosen by default');
    }

    public function testOtherGroupIsSelectable(): void
    {
        $result = OfficeList::build(self::offices(), self::groupRows(), self::cart(), false, 2, '');

        self::assertSame([3], array_column($result['points'], 'id'));
    }

    public function testSearchByPostcode(): void
    {
        $result = OfficeList::build(self::offices(), self::groupRows(), self::cart(), false, 1, '21002');

        self::assertSame([2], array_column($result['points'], 'id'));
        self::assertSame('21002', $result['points'][0]['postcode']);
    }

    public function testPostcodePrefixMatches(): void
    {
        $offices = [self::office(1, '21001', 'office'), self::office(2, '21010', 'office'), self::office(3, '21100', 'office')];

        $result = OfficeList::build($offices, self::groupRows(), self::cart(), false, 1, '2101');

        self::assertSame([2], array_column($result['points'], 'id'));
    }

    public function testSearchByNumberInNameAndByStreet(): void
    {
        self::assertSame([2], array_column(OfficeList::build(self::offices(), self::groupRows(), self::cart(), false, 1, 'Вінниця 2')['points'], 'id'));
        self::assertSame([2], array_column(OfficeList::build(self::offices(), self::groupRows(), self::cart(), false, 1, 'келецька 5')['points'], 'id'));
    }

    public function testLockerWithCodIsGreyedWithReason(): void
    {
        $result = OfficeList::build(self::offices(), self::groupRows(), self::cart(), true, 2, '');

        self::assertTrue($result['points'][0]['disabled']);
        self::assertSame('cod_not_allowed', $result['points'][0]['reason']);
    }

    public function testFittingPointsComeFirst(): void
    {
        $heavy = new CartMetrics(31.0, false, 100.0);

        $result = OfficeList::build(self::offices(), self::groupRows(), $heavy, false, 1, '');

        self::assertTrue($result['points'][0]['disabled']);
        self::assertSame('too_heavy', $result['points'][0]['reason']);
    }

    public function testPageIsCapped(): void
    {
        $many = [];
        for ($i = 1; $i <= OfficeList::PAGE + 20; $i++) {
            $many[] = self::office($i, sprintf('%05d', 30000 + $i), 'office');
        }

        $result = OfficeList::build($many, self::groupRows(), self::cart(), false, 1, '');

        self::assertCount(OfficeList::PAGE, $result['points']);
        self::assertSame(OfficeList::PAGE + 20, $result['groups'][0]['count']);
    }
}
