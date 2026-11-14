<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows;

/**
 * Населений пункт, узятий із запису відділення. Міста існують лише там, де є пункт: повний обхід
 * населених пунктів класифікатора (~30k) кошику не потрібен — у місто без пункту не доставити.
 */
final class CityRow
{
    /**
     * @param array<string, mixed> $entry запис відділення; `REGION_ID` — область обходу
     * @param int $points скільки пунктів у місті: порядок підказок без підзапиту на кожен рядок пошуку
     */
    public static function fromOffice(array $entry, int $points = 0): array
    {
        $name = Fit::text(Classifier::field($entry, ['CITY_UA']), 255) ?? '';

        return RowHash::with([
            'id' => Fit::integer(Classifier::field($entry, ['POCITY_ID', 'CITY_ID'])) ?? 0,
            'region_id' => Fit::integer(Classifier::field($entry, ['REGION_ID'])) ?? 0,
            'name_ua' => $name,
            'type_ua' => Fit::text(Classifier::field($entry, ['SHORTCITYTYPE_UA', 'CITYTYPE_UA']), 64),
            'district_ua' => Fit::text(Classifier::field($entry, ['DISTRICT_UA']), 255),
            'region_ua' => Fit::text(Classifier::field($entry, ['REGION_UA']), 255),
            'search_text' => NameNormalizer::searchText($name, []),
            'points' => $points,
        ]);
    }
}
