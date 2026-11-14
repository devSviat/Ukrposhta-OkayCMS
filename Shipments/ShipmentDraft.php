<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;

/** Відправлення замовлення, яким воно піде в Укрпошту: значення з замовлення й налаштувань, правлені менеджером у формі. */
final readonly class ShipmentDraft
{
    public const int MAX_WEIGHT_G = 30000;
    public const int MAX_SIDE_CM = 120;
    public const int MAX_OTHER_SIDE_CM = 70;

    /** Платить одержувач: у пункт-локер і PUDO Укрпошта від нього нічого не бере. */
    public bool $paidByRecipient;

    public function __construct(
        public string $type,
        public int $weightG,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public float $declaredPrice,
        public bool $cod,
        public float $codAmount,
        bool $paidByRecipient,
        public bool $codPaidByRecipient,
        public string $onFail,
        public string $description,
        public bool $sms,
        public PersonName $name,
        public string $phone,
        public string $officePostcode,
        public string $officeKind,
        /** Спосіб оплати замовлення — післяплата: без цього післяплату відправленню не вмикати. */
        public bool $paymentIsCod,
    ) {
        $this->paidByRecipient = $paidByRecipient && !$this->toLocker();
    }

    /** Усе, що не звичайне відділення (локер, PUDO), лічиться пунктом без післяплати: невідомий вид теж. */
    public function toLocker(): bool
    {
        return OfficeKind::tryFrom($this->officeKind) !== OfficeKind::Office;
    }

    /** @return array<string, string> мовні ключі помилок за полем форми */
    public function errors(): array
    {
        $errors = [];
        if (!in_array($this->type, ShippingConfig::TYPES, true)) {
            $errors['type'] = 'sviat__ukrposhta__ship_err_type';
        }
        if ($this->weightG < 1 || $this->weightG > self::MAX_WEIGHT_G) {
            $errors['weight'] = 'sviat__ukrposhta__ship_err_weight';
        }
        if (!self::sidesFit([$this->lengthCm, $this->widthCm, $this->heightCm])) {
            $errors['sides'] = 'sviat__ukrposhta__ship_err_sides';
        }
        if ($this->declaredPrice <= 0) {
            $errors['declared_price'] = 'sviat__ukrposhta__ship_err_declared';
        }
        if (!in_array($this->onFail, ShipmentConfig::ON_FAIL_CHOICES, true)) {
            $errors['on_fail'] = 'sviat__ukrposhta__ship_err_on_fail';
        }
        if ($this->cod) {
            if (!$this->paymentIsCod) {
                $errors['cod'] = 'sviat__ukrposhta__ship_err_cod_not_allowed';
            } elseif ($this->toLocker()) {
                // Покупець міг обрати локер, а оплату змінили на післяплату вже в замовленні: Укрпошта такого не приймає.
                $errors['cod'] = 'sviat__ukrposhta__ship_err_cod_locker';
            }
            if ($this->codAmount <= 0) {
                $errors['cod_amount'] = 'sviat__ukrposhta__ship_err_cod_amount';
            } elseif ($this->codAmount > $this->declaredPrice + 1e-9) {
                $errors['cod_amount'] = 'sviat__ukrposhta__ship_err_cod_above_declared';
            }
        }
        $description = trim($this->description);
        if ($description === '' || mb_strlen($description) > ShipmentDescription::MAX_LENGTH) {
            $errors['description'] = 'sviat__ukrposhta__ship_err_description';
        }
        if (UpPhone::normalize($this->phone) === null) {
            $errors['phone'] = 'sviat__ukrposhta__ship_err_phone';
        }
        if (($nameIssue = $this->name->validate()) !== null) {
            $errors['name'] = $nameIssue;
        }

        return $errors;
    }

    /** @return list<string> мовні ключі помилок; порожньо — відправлення можна створювати */
    public function issues(): array
    {
        return array_values(array_unique($this->errors()));
    }

    /**
     * Найбільша сторона до 120 см, дві інші до 70 см; усі три потрібні (Express і Standard без габаритів не приймаються).
     *
     * @param list<int> $sides
     */
    private static function sidesFit(array $sides): bool
    {
        rsort($sides);

        return min($sides) >= 1 && $sides[0] <= self::MAX_SIDE_CM && $sides[1] <= self::MAX_OTHER_SIDE_CM && $sides[2] <= self::MAX_OTHER_SIDE_CM;
    }

    /**
     * Накладає надіслану форму; поля, яких у ній немає, лишаються як були.
     *
     * @param array<string, string|float|bool> $form ShipmentInput::fromPost()
     * @return self|array<string, string> чернетка або помилки за полем
     */
    public function withInput(array $form): self|array
    {
        $errors = [];
        $number = function (string $field, string $errorField) use ($form, &$errors): ?float {
            if (!array_key_exists($field, $form)) {
                return null;
            }
            if (!is_float($form[$field])) {
                $errors[$errorField] = match ($errorField) {
                    'weight' => 'sviat__ukrposhta__ship_err_weight',
                    'sides' => 'sviat__ukrposhta__ship_err_sides',
                    'cod_amount' => 'sviat__ukrposhta__ship_err_cod_amount',
                    default => 'sviat__ukrposhta__ship_err_declared',
                };

                return null;
            }

            return $form[$field];
        };
        $text = static fn(string $field, string $current): string => is_string($form[$field] ?? null) ? $form[$field] : $current;
        $switch = static fn(string $field, bool $current): bool => array_key_exists($field, $form) ? (bool)$form[$field] : $current;

        $weight = $number('weight', 'weight');
        $length = $number('length', 'sides');
        $width = $number('width', 'sides');
        $height = $number('height', 'sides');
        $declared = $number('declared_price', 'declared_price');
        $codAmount = $number('cod_amount', 'cod_amount');
        if ($errors !== []) {
            return $errors;
        }

        $draft = new self(
            $text('type', $this->type),
            $weight !== null ? (int)round($weight) : $this->weightG,
            $length !== null ? (int)round($length) : $this->lengthCm,
            $width !== null ? (int)round($width) : $this->widthCm,
            $height !== null ? (int)round($height) : $this->heightCm,
            $declared !== null ? round($declared, 2) : $this->declaredPrice,
            $switch('cod', $this->cod),
            $codAmount !== null ? round($codAmount, 2) : $this->codAmount,
            array_key_exists('payer', $form) ? $form['payer'] === 'recipient' : $this->paidByRecipient,
            array_key_exists('cod_payer', $form) ? $form['cod_payer'] === 'recipient' : $this->codPaidByRecipient,
            $text('on_fail', $this->onFail),
            $text('description', $this->description),
            $switch('sms', $this->sms),
            new PersonName($text('last_name', $this->name->last), $text('first_name', $this->name->first), $text('middle_name', $this->name->middle)),
            self::phone($text('phone', $this->phone)),
            $this->officePostcode,
            $this->officeKind,
            $this->paymentIsCod,
        );
        $errors = $draft->errors();

        return $errors === [] ? $draft : $errors;
    }

    /** Нормалізований телефон, а коли він хибний — як ввели, щоб форма показала помилку з тим, що менеджер набрав. */
    public static function phone(string $raw): string
    {
        return UpPhone::normalize($raw) ?? $raw;
    }

    /** @return array<string, mixed> тіло `POST /shipments` */
    public function toRequest(string $senderUuid, string $recipientUuid, int $recipientAddressId, int $orderId): array
    {
        $request = [
            'sender' => ['uuid' => $senderUuid],
            'recipient' => ['uuid' => $recipientUuid],
            'recipientAddressId' => $recipientAddressId,
            'deliveryType' => 'W2W',
            'type' => $this->type,
            'parcels' => [[
                'weight' => $this->weightG,
                'length' => $this->lengthCm,
                'width' => $this->widthCm,
                'height' => $this->heightCm,
                'declaredPrice' => $this->declaredPrice,
            ]],
            'declaredPrice' => $this->declaredPrice,
        ];
        if ($this->cod) {
            $request['postPay'] = $this->codAmount;
            // Гроші йдуть на IBAN відправника з налаштувань: готівкою фізособі магазин не працює.
            $request['transferPostPayToBankAccount'] = true;
        }

        return $request + [
            'postPayPaidByRecipient' => $this->codPaidByRecipient,
            'paidByRecipient' => $this->paidByRecipient,
            'onFailReceiveType' => $this->onFail,
            'description' => mb_substr($this->description, 0, ShipmentInput::DESCRIPTION_MAX),
            'sms' => $this->sms,
            'externalId' => (string)$orderId,
        ];
    }
}
