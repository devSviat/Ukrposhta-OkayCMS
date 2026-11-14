<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Quote\ShippingConfig;

/** Блок «Відправник»: магазин відправляє як ТОВ або як ФОП, реквізити різні. */
final class SenderSettings
{
    public const string TYPE = 'sviat__ukrposhta__sender_type';
    public const string NAME = 'sviat__ukrposhta__sender_name';
    public const string EDRPOU = 'sviat__ukrposhta__sender_edrpou';
    public const string LAST_NAME = 'sviat__ukrposhta__sender_last_name';
    public const string FIRST_NAME = 'sviat__ukrposhta__sender_first_name';
    public const string TIN = 'sviat__ukrposhta__sender_tin';
    public const string PHONE = 'sviat__ukrposhta__sender_phone';
    public const string IBAN = 'sviat__ukrposhta__sender_iban';
    public const string POSTCODE = ShippingConfig::SENDER_POSTCODE;

    public const string COMPANY = 'COMPANY';
    public const string PRIVATE_ENTREPRENEUR = 'PRIVATE_ENTREPRENEUR';

    /** Межі API клієнта eCom: назва юрособи 2–60 символів, прізвище й ім'я 2–250. */
    public const int COMPANY_NAME_MAX = 60;
    public const int PERSON_NAME_MAX = 250;
    private const int NAME_MIN = 2;

    /** Поле форми → налаштування; спільні для обох типів. */
    private const array COMMON = ['sender_phone' => self::PHONE, 'sender_iban' => self::IBAN, 'sender_postcode' => self::POSTCODE];
    private const array BY_TYPE = [
        self::COMPANY => ['sender_name' => self::NAME, 'sender_edrpou' => self::EDRPOU],
        self::PRIVATE_ENTREPRENEUR => ['sender_last_name' => self::LAST_NAME, 'sender_first_name' => self::FIRST_NAME, 'sender_tin' => self::TIN],
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly SenderOffices $offices,
    ) {
    }

    /** @return array{type:string, name:string, edrpou:string, last_name:string, first_name:string, tin:string, phone:string, iban:string, postcode:string} */
    public function read(): array
    {
        return self::fromSettings($this->settings->get(...));
    }

    /**
     * @param callable(string): mixed $get Settings::get
     * @return array{type:string, name:string, edrpou:string, last_name:string, first_name:string, tin:string, phone:string, iban:string, postcode:string}
     */
    public static function fromSettings(callable $get): array
    {
        $text = static fn(string $key): string => is_scalar($value = $get($key)) ? trim((string)$value) : '';

        return [
            'type' => $text(self::TYPE) === self::PRIVATE_ENTREPRENEUR ? self::PRIVATE_ENTREPRENEUR : self::COMPANY,
            'name' => $text(self::NAME),
            'edrpou' => $text(self::EDRPOU),
            'last_name' => $text(self::LAST_NAME),
            'first_name' => $text(self::FIRST_NAME),
            'tin' => $text(self::TIN),
            'phone' => $text(self::PHONE),
            'iban' => $text(self::IBAN),
            'postcode' => $text(self::POSTCODE),
        ];
    }

    /**
     * Надіслане, у формі read(): форму з помилками показуємо з тим, що менеджер ввів, а не зі збереженим.
     *
     * @param array<string, mixed> $post
     * @return array{type:string, name:string, edrpou:string, last_name:string, first_name:string, tin:string, phone:string, iban:string, postcode:string}
     */
    public static function fromPost(array $post): array
    {
        $values = self::normalize($post);

        return [
            'type' => self::text($post, 'sender_type') === self::PRIVATE_ENTREPRENEUR ? self::PRIVATE_ENTREPRENEUR : self::COMPANY,
            'name' => $values['sender_name'],
            'edrpou' => $values['sender_edrpou'],
            'last_name' => $values['sender_last_name'],
            'first_name' => $values['sender_first_name'],
            'tin' => $values['sender_tin'],
            'phone' => $values['sender_phone'],
            'iban' => $values['sender_iban'],
            'postcode' => $values['sender_postcode'],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> поле форми → мовний ключ помилки; порожньо, якщо все гаразд
     */
    public function validate(array $post): array
    {
        $type = self::text($post, 'sender_type');
        if (!isset(self::BY_TYPE[$type])) {
            return ['sender_type' => 'sviat__ukrposhta__sender_error_type'];
        }
        $values = self::normalize($post);
        $errors = [];
        if ($type === self::COMPANY) {
            if (!self::lengthWithin($values['sender_name'], self::COMPANY_NAME_MAX)) {
                $errors['sender_name'] = 'sviat__ukrposhta__sender_error_name';
            }
            if (!preg_match('/^\d{8}$/', $values['sender_edrpou'])) {
                $errors['sender_edrpou'] = 'sviat__ukrposhta__sender_error_edrpou';
            }
        } else {
            if (!self::lengthWithin($values['sender_last_name'], self::PERSON_NAME_MAX)) {
                $errors['sender_last_name'] = 'sviat__ukrposhta__sender_error_last_name';
            }
            if (!self::lengthWithin($values['sender_first_name'], self::PERSON_NAME_MAX)) {
                $errors['sender_first_name'] = 'sviat__ukrposhta__sender_error_first_name';
            }
            if (!preg_match('/^\d{10}$/', $values['sender_tin'])) {
                $errors['sender_tin'] = 'sviat__ukrposhta__sender_error_tin';
            }
        }
        // Нормалізація викидає все, крім цифр, тож літери перевіряються в тому, що ввели.
        if (!preg_match('/^[\d\s()+\-]*$/', self::text($post, 'sender_phone')) || !preg_match('/^\+380\d{9}$/', $values['sender_phone'])) {
            $errors['sender_phone'] = 'sviat__ukrposhta__sender_error_phone';
        }
        if (!self::validIban($values['sender_iban'])) {
            $errors['sender_iban'] = 'sviat__ukrposhta__sender_error_iban';
        }
        if (!preg_match('/^\d{5}$/', $values['sender_postcode'])) {
            $errors['sender_postcode'] = 'sviat__ukrposhta__sender_error_postcode';
        } elseif (!$this->offices->exists($values['sender_postcode'])) {
            $errors['sender_postcode'] = 'sviat__ukrposhta__sender_error_postcode_unknown';
        }

        return $errors;
    }

    /**
     * Зберігає поля обраного типу й спільні; поля іншого типу лишаються, щоб перемикач не стирав їх.
     *
     * @param array<string, mixed> $post
     * @return array<string, string> помилки з validate(); якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        $errors = $this->validate($post);
        if ($errors !== []) {
            return $errors;
        }
        $type = self::text($post, 'sender_type');
        $values = self::normalize($post);
        $this->settings->set(self::TYPE, $type);
        foreach (self::BY_TYPE[$type] + self::COMMON as $field => $setting) {
            $this->settings->set($setting, $values[$field]);
        }

        return [];
    }

    /** @return array<string, string> поле форми → значення в тому вигляді, в якому воно зберігається */
    private static function normalize(array $post): array
    {
        $digits = static fn(string $field): string => preg_replace('/[\s-]+/u', '', self::text($post, $field)) ?? '';

        return [
            'sender_name' => self::text($post, 'sender_name'),
            'sender_edrpou' => $digits('sender_edrpou'),
            'sender_last_name' => self::text($post, 'sender_last_name'),
            'sender_first_name' => self::text($post, 'sender_first_name'),
            'sender_tin' => $digits('sender_tin'),
            'sender_phone' => self::phone(self::text($post, 'sender_phone')),
            'sender_iban' => strtoupper(preg_replace('/\s+/u', '', self::text($post, 'sender_iban')) ?? ''),
            'sender_postcode' => self::text($post, 'sender_postcode'),
        ];
    }

    /** Приймає номер так, як його зазвичай пишуть: "+380 67 …", "380…", "067…"; повертає +380XXXXXXXXX. */
    private static function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }

        return $digits === '' ? '' : '+' . $digits;
    }

    /** UA, дві контрольні цифри й 25 цифр рахунку; контрольну суму рахує ISO 13616 (mod 97), тож одна хибна цифра не пройде. */
    private static function validIban(string $iban): bool
    {
        if (!preg_match('/^UA\d{27}$/', $iban)) {
            return false;
        }
        // U = 30, A = 10 за ISO 13616; рахуємо залишок частинами, бо число завдовжки 31 цифра.
        $number = substr($iban, 4) . '3010' . substr($iban, 2, 2);
        $remainder = 0;
        foreach (str_split($number, 7) as $chunk) {
            $remainder = (int)($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    private static function lengthWithin(string $value, int $max): bool
    {
        $length = mb_strlen($value);

        return $length >= self::NAME_MIN && $length <= $max;
    }

    private static function text(array $post, string $field): string
    {
        return is_string($post[$field] ?? null) ? trim($post[$field]) : '';
    }
}
