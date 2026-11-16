<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/**
 * Знімок відправлення для картки (колонка `details`), без нових запитів: з чернетки й відповіді створення або з
 * відповіді `GET shipments/...` при прив'язці. Лише білий список ключів, текст обрізаний, числа приведені;
 * те саме очищення стоїть і на читанні, тож зіпсований JSON дає порожній знімок.
 */
final class ShipmentDetails
{
    /** Ліміти як у полях форми (ShipmentInput) і колонок модуля. */
    private const array TEXT = [
        'type' => 16,
        'description' => ShipmentInput::DESCRIPTION_MAX,
        'on_fail' => 32,
        'last_name' => 250,
        'first_name' => 250,
        'middle_name' => 250,
        'phone' => 20,
        'postcode' => 5,
    ];
    private const array SIZES = ['weight_g', 'length', 'width', 'height'];

    /**
     * Те, що пішло в `POST /shipments`, і планова дата з відповіді.
     *
     * @param array<mixed> $answer
     * @return array<string, string|int|float|bool>
     */
    public static function fromDraft(ShipmentDraft $draft, array $answer): array
    {
        return self::clean([
            'type' => $draft->type,
            'description' => $draft->description,
            'on_fail' => $draft->onFail,
            'last_name' => $draft->name->last,
            'first_name' => $draft->name->first,
            'middle_name' => $draft->name->middle,
            'phone' => UpPhone::normalize($draft->phone) ?? $draft->phone,
            'postcode' => $draft->officePostcode,
            'delivery_date' => $answer['deliveryDate'] ?? null,
            'weight_g' => $draft->weightG,
            'length' => $draft->lengthCm,
            'width' => $draft->widthCm,
            'height' => $draft->heightCm,
            'declared' => $draft->declaredPrice,
            'sms' => $draft->sms,
        ]);
    }

    /**
     * Відправлення з відповіді eCom; ключі ті самі, що модуль надсилає в `POST /shipments` і `POST /clients`.
     *
     * @param array<mixed> $item
     * @return array<string, string|int|float|bool>
     */
    public static function fromApi(array $item): array
    {
        $parcel = is_array($item['parcels'] ?? null) && is_array($item['parcels'][0] ?? null) ? $item['parcels'][0] : [];
        $recipient = is_array($item['recipient'] ?? null) ? $item['recipient'] : [];

        return self::clean([
            'type' => $item['type'] ?? null,
            'description' => $item['description'] ?? null,
            'on_fail' => $item['onFailReceiveType'] ?? null,
            'last_name' => $recipient['lastName'] ?? null,
            'first_name' => $recipient['firstName'] ?? null,
            'middle_name' => $recipient['middleName'] ?? null,
            'phone' => $recipient['phoneNumber'] ?? null,
            'delivery_date' => $item['deliveryDate'] ?? null,
            'weight_g' => $parcel['weight'] ?? null,
            'length' => $parcel['length'] ?? null,
            'width' => $parcel['width'] ?? null,
            'height' => $parcel['height'] ?? null,
            'declared' => $item['declaredPrice'] ?? null,
            'sms' => $item['sms'] ?? null,
        ]);
    }

    /** @param array<string, string|int|float|bool> $details */
    public static function encode(array $details): ?string
    {
        $details = self::clean($details);
        if ($details === []) {
            return null;
        }
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /** @return array<string, string|int|float|bool> */
    public static function decode(mixed $json): array
    {
        $raw = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($raw) && !array_is_list($raw) ? self::clean($raw) : [];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, string|int|float|bool>
     */
    private static function clean(array $raw): array
    {
        $details = [];
        foreach (self::TEXT as $key => $limit) {
            $value = is_string($raw[$key] ?? null) ? mb_substr(trim($raw[$key]), 0, $limit) : '';
            if ($value !== '' && ($key !== 'postcode' || ctype_digit($value))) {
                $details[$key] = $value;
            }
        }
        $date = self::date($raw['delivery_date'] ?? null);
        if ($date !== null) {
            $details['delivery_date'] = $date;
        }
        foreach (self::SIZES as $key) {
            $value = $raw[$key] ?? null;
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) {
                $details[$key] = (int)$value;
            }
        }
        $declared = $raw['declared'] ?? null;
        if ((is_int($declared) || is_float($declared) || (is_string($declared) && is_numeric($declared))) && (float)$declared > 0) {
            $details['declared'] = round((float)$declared, 2);
        }
        if (is_bool($raw['sms'] ?? null)) {
            $details['sms'] = $raw['sms'];
        }

        return $details;
    }

    /** Дата без часу: `deliveryDate` може прийти і як `2026-11-19`, і з часом. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
