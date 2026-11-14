<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows;

/** Запис відділення з класифікатора як рядок таблиці, або `null` для пункту, якого не має бути в кошику. */
final class OfficeRow
{
    /** @param array<string, mixed> $entry запис `get_postoffices_by_city_id`; `REGION_ID` — область обходу */
    public static function fromClassifier(array $entry): ?array
    {
        // Відсутній LOCK_CODE — теж виключення: ендпоінт, що перестав його віддавати, дасть порожню область,
        // а її старі рядки збереже перевірка правдоподібності.
        if (Classifier::field($entry, ['LOCK_CODE']) !== '0'
            || Classifier::flag($entry, ['IS_SECURITY']) === 1
            || Classifier::flag($entry, ['RESTRICTED_ACCESS']) === 1) {
            return null;
        }
        $kind = OfficeKind::fromType((string)Classifier::field($entry, ['TYPE_ACRONYM']), Classifier::field($entry, ['TYPE_SHORT']));
        $id = Fit::integer(Classifier::field($entry, ['ID', 'POSTOFFICE_ID']));
        $postcode = self::postcode(Classifier::field($entry, ['POSTCODE']));
        // Без індексу відправлення не створити: саме POSTCODE, а не id, адресує пункт в eCom.
        if ($kind === null || $id === null || $id <= 0 || $postcode === null) {
            return null;
        }

        return RowHash::with([
            'id' => $id,
            'region_id' => Fit::integer(Classifier::field($entry, ['REGION_ID'])) ?? 0,
            'city_id' => Fit::integer(Classifier::field($entry, ['POCITY_ID', 'CITY_ID'])) ?? 0,
            'postcode' => $postcode,
            'postindex' => Fit::text(Classifier::field($entry, ['POSTINDEX']), 10),
            'kind' => $kind->value,
            'name_ua' => Fit::text(Classifier::field($entry, ['POSTOFFICE_UA', 'PO_LONG', 'PO_SHORT']), 255) ?? '',
            'address_ua' => Fit::text(Classifier::field($entry, ['ADDRESS', 'STREET_UA_VPZ']), 255),
            'lat' => Fit::decimal(Classifier::field($entry, ['LATTITUDE', 'LATITUDE']), 999.9999999),
            'lng' => Fit::decimal(Classifier::field($entry, ['LONGITUDE']), 999.9999999),
            'subtype_id' => Fit::integer(Classifier::field($entry, ['SUBTYPE_ID', 'POSTOFFICE_SUBTYPE_ID'])),
            'is_cash' => Classifier::flag($entry, ['IS_CASH', 'ISCASH']),
            'is_automated' => Classifier::flag($entry, ['ISAUTOMATED', 'IS_AUTOMATED']),
            // Розклад підтягується окремим запитом, коли пункт уперше показано; змінений запис скидає його,
            // щоб не показувати графік старої адреси.
            'schedule_json' => null,
        ]);
    }

    /** Числа приходять рядками, але окремі ендпоінти віддають числом і гублять провідний нуль (01001 → 1001). */
    private static function postcode(?string $value): ?string
    {
        if ($value === null || !ctype_digit($value) || strlen($value) > 5) {
            return null;
        }

        return str_pad($value, 5, '0', STR_PAD_LEFT);
    }
}
