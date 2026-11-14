<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;

/**
 * Значення за замовчуванням для нового відправлення: блок налаштувань «Відправлення за замовчуванням».
 * Сторінка налаштувань лише записує їх; читаються вони тут, щоб відправлення не залежали від адмінки.
 */
final readonly class ShipmentConfig
{
    public const string PAYER = 'sviat__ukrposhta__payer';
    public const string COD_PAYER = 'sviat__ukrposhta__cod_payer';
    public const string ON_FAIL = 'sviat__ukrposhta__on_fail';
    public const string DESCRIPTION = 'sviat__ukrposhta__description';
    public const string DESCRIPTION_MODE = 'sviat__ukrposhta__description_mode';
    public const string SMS = 'sviat__ukrposhta__sms';
    public const string LABEL_SIZE = 'sviat__ukrposhta__label_size';

    public const array PAYERS = ['recipient', 'sender'];
    public const array ON_FAIL_CHOICES = ['RETURN', 'PROCESS_AS_REFUSAL'];
    /** Перший — типовий: термопринтер 10×10. */
    public const array LABEL_SIZES = [Labels::SIZE_10X10, Labels::SIZE_A4];

    public function __construct(
        public ShippingConfig $shipping,
        public bool $payerIsRecipient,
        public bool $codPayerIsRecipient,
        public string $onFail,
        public string $description,
        public bool $sms,
        public string $labelSize = Labels::SIZE_10X10,
        /** ShipmentDescription::MODES; $description — власний текст для режиму TEXT */
        public string $descriptionMode = ShipmentDescription::PRODUCTS,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        $choice = static fn(string $key, array $allowed): string => in_array($value = $get($key), $allowed, true) ? $value : $allowed[0];

        return new self(
            ShippingConfig::fromSettings($get),
            $choice(self::PAYER, self::PAYERS) === 'recipient',
            $choice(self::COD_PAYER, self::PAYERS) === 'recipient',
            $choice(self::ON_FAIL, self::ON_FAIL_CHOICES),
            is_string($description = $get(self::DESCRIPTION)) ? $description : '',
            !empty($get(self::SMS)),
            $choice(self::LABEL_SIZE, self::LABEL_SIZES),
            $choice(self::DESCRIPTION_MODE, ShipmentDescription::MODES),
        );
    }
}
