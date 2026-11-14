<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Форма відправлення, як її надіслано: лише відомі поля, текст обрізано до ліміту; решту перевіряє ShipmentDraft::withInput. */
final class ShipmentInput
{
    public const int DESCRIPTION_MAX = ShipmentDescription::MAX_LENGTH;

    private const array TEXT = [
        'type' => 16,
        'payer' => 16,
        'cod_payer' => 16,
        'on_fail' => 32,
        'description' => self::DESCRIPTION_MAX,
        'last_name' => 250,
        'first_name' => 250,
        'middle_name' => 250,
        'phone' => 20,
    ];
    private const array NUMBERS = ['weight', 'length', 'width', 'height', 'declared_price', 'cod_amount'];
    private const array SWITCHES = ['cod', 'sms'];

    /** @return array<string, string|float|bool> */
    public static function fromPost(array $post): array
    {
        $input = [];
        foreach (self::TEXT as $name => $limit) {
            if (is_string($post[$name] ?? null)) {
                $input[$name] = mb_substr(trim($post[$name]), 0, $limit);
            }
        }
        foreach (self::NUMBERS as $name) {
            if (is_string($post[$name] ?? null)) {
                $raw = str_replace(',', '.', trim($post[$name]));
                // Не число лишається текстом, щоб чернетка повідомила про поле, а не зберегла старе значення.
                $input[$name] = is_numeric($raw) ? (float)$raw : mb_substr($raw, 0, 20);
            }
        }
        // Знятий прапорець у POST не надходить, тож відсутній перемикач — це «вимкнено».
        foreach (self::SWITCHES as $name) {
            $input[$name] = is_string($post[$name] ?? null) && $post[$name] !== '' && $post[$name] !== '0';
        }

        return $input;
    }
}
