<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

/** Загальні для магазину значення, з яких рахується ціна доставки. */
final readonly class ShippingConfig
{
    public const string SENDER_POSTCODE = 'sviat__ukrposhta__sender_postcode';
    public const string TYPE = 'sviat__ukrposhta__shipment_type';
    public const string DEFAULT_WEIGHT = 'sviat__ukrposhta__default_weight';
    public const string BOX_LENGTH = 'sviat__ukrposhta__box_length';
    public const string BOX_WIDTH = 'sviat__ukrposhta__box_width';
    public const string BOX_HEIGHT = 'sviat__ukrposhta__box_height';
    public const string DECLARE_TOTAL = 'sviat__ukrposhta__declare_total';
    public const string DISCOUNT = 'sviat__ukrposhta__discount';

    public const array TYPES = ['EXPRESS', 'STANDARD'];
    public const float DEFAULT_WEIGHT_KG = 1.0;
    public const int DEFAULT_LENGTH_CM = 30;
    public const int DEFAULT_WIDTH_CM = 20;
    public const int DEFAULT_HEIGHT_CM = 15;

    public function __construct(
        public string $senderPostcode,
        public string $type,
        public float $defaultWeightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public bool $declareCartTotal,
        public float $discountPercent,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        $type = strtoupper(trim((string)$get(self::TYPE)));
        $weight = (float)$get(self::DEFAULT_WEIGHT);
        $declare = $get(self::DECLARE_TOTAL);
        $discount = (float)$get(self::DISCOUNT);

        return new self(
            trim((string)$get(self::SENDER_POSTCODE)),
            in_array($type, self::TYPES, true) ? $type : 'STANDARD',
            $weight > 0 ? $weight : self::DEFAULT_WEIGHT_KG,
            self::side($get(self::BOX_LENGTH), self::DEFAULT_LENGTH_CM),
            self::side($get(self::BOX_WIDTH), self::DEFAULT_WIDTH_CM),
            self::side($get(self::BOX_HEIGHT), self::DEFAULT_HEIGHT_CM),
            // Не збережене налаштування — це типове "так", а не "ні".
            $declare === null || $declare === '' ? true : (bool)(int)$declare,
            min(100.0, max(0.0, $discount)),
        );
    }

    private static function side(mixed $value, int $default): int
    {
        $side = (int)ceil((float)$value);

        return $side > 0 ? $side : $default;
    }
}
