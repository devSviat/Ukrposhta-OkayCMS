<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentConfig;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDescription;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;

/** Блок «Відправлення за замовчуванням»: з цих значень рахується ціна й заповнюється форма відправлення. */
final class ShippingSettings
{
    /** Поле форми → налаштування й суфікс мовного ключа помилки. */
    private const array SIDES = [
        'box_length' => [ShippingConfig::BOX_LENGTH, 'length'],
        'box_width' => [ShippingConfig::BOX_WIDTH, 'width'],
        'box_height' => [ShippingConfig::BOX_HEIGHT, 'height'],
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function read(): ShipmentConfig
    {
        return ShipmentConfig::fromSettings($this->settings->get(...));
    }

    /**
     * Значення полів форми зі збережених налаштувань.
     *
     * @return array{type:string, weight:string, length:string, width:string, height:string, declare_total:bool, discount:string, payer:string, cod_payer:string, on_fail:string, description_mode:string, description:string, sms:bool, label_size:string}
     */
    public static function form(ShipmentConfig $saved): array
    {
        $config = $saved->shipping;

        return [
            'type' => $config->type,
            'weight' => (string)$config->defaultWeightKg,
            'length' => (string)$config->lengthCm,
            'width' => (string)$config->widthCm,
            'height' => (string)$config->heightCm,
            'declare_total' => $config->declareCartTotal,
            'discount' => (string)$config->discountPercent,
            'payer' => $saved->payerIsRecipient ? 'recipient' : 'sender',
            'cod_payer' => $saved->codPayerIsRecipient ? 'recipient' : 'sender',
            'on_fail' => $saved->onFail,
            'description_mode' => $saved->descriptionMode,
            'description' => $saved->description,
            'sms' => $saved->sms,
            'label_size' => $saved->labelSize,
        ];
    }

    /**
     * Надіслане як є: форму з помилками показуємо з тим, що менеджер ввів, навіть із хибними значеннями.
     *
     * @return array{type:string, weight:string, length:string, width:string, height:string, declare_total:bool, discount:string, payer:string, cod_payer:string, on_fail:string, description_mode:string, description:string, sms:bool, label_size:string}
     */
    public static function formFromPost(array $post): array
    {
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? $post[$field] : '';

        return [
            'type' => $text('shipment_type'),
            'weight' => $text('default_weight'),
            'length' => $text('box_length'),
            'width' => $text('box_width'),
            'height' => $text('box_height'),
            'declare_total' => !empty($post['declare_total']),
            'discount' => $text('discount'),
            'payer' => $text('payer'),
            'cod_payer' => $text('cod_payer'),
            'on_fail' => $text('on_fail'),
            'description_mode' => $text('description_mode'),
            'description' => $text('description'),
            'sms' => !empty($post['sms']),
            'label_size' => $text('label_size'),
        ];
    }

    /** @return array<string, string> поле форми → мовний ключ помилки; якщо вони є, нічого не зберігається */
    public function save(array $post): array
    {
        $parsed = self::parse($post);
        if ($parsed['errors'] === []) {
            foreach ($parsed['values'] as $name => $value) {
                $this->settings->set($name, $value);
            }
        }

        return $parsed['errors'];
    }

    /**
     * Ці значення йдуть у розрахунок ціни в кошику, тож хибне не зберігається ніколи: краще помилка в адмінці,
     * ніж відмова Укрпошти на кожен запит ціни.
     *
     * @return array{values: array<string, string|int|float>, errors: array<string, string>}
     */
    public static function parse(array $post): array
    {
        $errors = [];
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        $choice = static function (string $field, array $allowed, string $error) use ($text, &$errors): string {
            $value = $text($field);
            if (!in_array($value, $allowed, true)) {
                $errors[$field] = 'sviat__ukrposhta__shipping_error_' . $error;
            }

            return $value;
        };

        $type = $choice('shipment_type', ShippingConfig::TYPES, 'type');
        $weight = self::decimal($text('default_weight'), 3);
        if ($weight === null || $weight <= 0 || $weight > ShipmentDraft::MAX_WEIGHT_G / 1000) {
            $errors['default_weight'] = 'sviat__ukrposhta__shipping_error_weight';
        }
        $sides = [];
        foreach (self::SIDES as $field => [$setting, $error]) {
            $side = preg_match('/^\d{1,3}$/', $text($field)) ? (int)$text($field) : 0;
            if ($side < 1 || $side > ShipmentDraft::MAX_SIDE_CM) {
                $errors[$field] = 'sviat__ukrposhta__shipping_error_' . $error;
                continue;
            }
            $sides[$setting] = $side;
        }
        if (count($sides) === 3) {
            $sorted = array_values($sides);
            rsort($sorted);
            if ($sorted[1] > ShipmentDraft::MAX_OTHER_SIDE_CM) {
                $errors['box'] = 'sviat__ukrposhta__shipping_error_box_sides';
            }
        }
        $discount = $text('discount') === '' ? 0.0 : self::decimal($text('discount'), 2);
        if ($discount === null || $discount > 100) {
            $errors['discount'] = 'sviat__ukrposhta__shipping_error_discount';
        }
        $payer = $choice('payer', ShipmentConfig::PAYERS, 'payer');
        $codPayer = $choice('cod_payer', ShipmentConfig::PAYERS, 'cod_payer');
        $onFail = $choice('on_fail', ShipmentConfig::ON_FAIL_CHOICES, 'on_fail');
        $labelSize = $choice('label_size', ShipmentConfig::LABEL_SIZES, 'label_size');
        $descriptionMode = $choice('description_mode', ShipmentDescription::MODES, 'description_mode');
        $description = preg_replace('/\s+/u', ' ', $text('description')) ?? '';
        $length = mb_strlen($description);
        if ($length > ShipmentDescription::MAX_LENGTH || ($descriptionMode === ShipmentDescription::TEXT && $length === 0)) {
            $errors['description'] = 'sviat__ukrposhta__shipping_error_description';
        }

        return [
            'values' => [
                ShippingConfig::TYPE => $type,
                ShippingConfig::DEFAULT_WEIGHT => (float)$weight,
                ...$sides,
                ShippingConfig::DECLARE_TOTAL => empty($post['declare_total']) ? 0 : 1,
                ShippingConfig::DISCOUNT => (float)$discount,
                ShipmentConfig::PAYER => $payer,
                ShipmentConfig::COD_PAYER => $codPayer,
                ShipmentConfig::ON_FAIL => $onFail,
                ShipmentConfig::DESCRIPTION_MODE => $descriptionMode,
                ShipmentConfig::DESCRIPTION => $description,
                ShipmentConfig::SMS => empty($post['sms']) ? 0 : 1,
                ShipmentConfig::LABEL_SIZE => $labelSize,
            ],
            'errors' => $errors,
        ];
    }

    /** Невід'ємне число з комою чи крапкою, як вводять в українській локалі; не більше $scale знаків після неї. Решта — null. */
    private static function decimal(string $raw, int $scale): ?float
    {
        return preg_match('/^\d{1,6}(?:[.,]\d{1,' . $scale . '})?$/', $raw) ? (float)str_replace(',', '.', $raw) : null;
    }
}
