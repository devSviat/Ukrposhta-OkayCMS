<?php

namespace Modules\Sviat\Ukrposhta\Support;

/** Записи класифікатора у формі, в якій їх віддає Укрпошта: усі значення — рядки. */
final class ClassifierFixtures
{
    /** @return array<string, string> */
    public static function office(int $id, array $overrides = []): array
    {
        return array_merge([
            'POSTOFFICE_ID' => (string)$id,
            'POSTCODE' => sprintf('%05d', 21000 + $id % 1000),
            'POSTINDEX' => sprintf('%05d', 21000 + $id % 1000),
            'POSTOFFICE_UA' => "Відділення {$id}",
            'STREET_UA_VPZ' => 'вул. Соборна, 1',
            'TYPE_ACRONYM' => 'МВ',
            'TYPE_SHORT' => 'Міське відділення',
            'LOCK_CODE' => '0',
            'IS_SECURITY' => '0',
            'RESTRICTED_ACCESS' => '0',
            'LATTITUDE' => '49.2328',
            'LONGITUDE' => '28.4810',
            'ISAUTOMATED' => '1',
            'IS_CASH' => '1',
            'CITY_ID' => '100',
            'CITY_UA' => 'Вінниця',
            'SHORTCITYTYPE_UA' => 'м.',
            'DISTRICT_UA' => 'Вінницький',
            'REGION_UA' => 'Вінницька',
        ], $overrides);
    }

    /** @return list<array<string, string>> */
    public static function offices(int $firstId, int $count, array $overrides = []): array
    {
        $rows = [];
        for ($id = $firstId; $id < $firstId + $count; $id++) {
            $rows[] = self::office($id, $overrides);
        }

        return $rows;
    }

    /** @param list<array> $entries */
    public static function answer(array $entries): array
    {
        return $entries === [] ? ['Entries' => []] : ['Entries' => ['Entry' => $entries]];
    }

    public static function region(int $id, string $name): array
    {
        return self::answer([['REGION_ID' => (string)$id, 'REGION_UA' => $name]]);
    }
}
