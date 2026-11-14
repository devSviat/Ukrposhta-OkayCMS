<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Прізвище, ім'я, по батькові одержувача для клієнта Укрпошти. */
final readonly class PersonName
{
    private const string PART = "/^[\p{L}][\p{L}'’ʼ\- ]*$/u";
    private const int MIN = 2;
    private const int MAX = 250;

    public function __construct(
        public string $last,
        public string $first,
        public string $middle = '',
    ) {
    }

    /** Покупець часто вписує все ПІБ в одне поле, тоді `last_name` порожнє й рядок ділимо за пробілами. */
    public static function fromOrder(object $order): self
    {
        $name = self::squash((string)($order->name ?? ''));
        $last = self::squash((string)($order->last_name ?? ''));
        if ($last !== '') {
            return new self($last, $name);
        }

        $words = $name === '' ? [] : explode(' ', $name, 3);

        return new self($words[0] ?? '', $words[1] ?? '', $words[2] ?? '');
    }

    /** @return string|null мовний ключ помилки */
    public function validate(): ?string
    {
        foreach ([$this->last, $this->first] as $required) {
            if (!self::valid($required)) {
                return 'sviat__ukrposhta__ship_err_name';
            }
        }

        return $this->middle === '' || self::valid($this->middle) ? null : 'sviat__ukrposhta__ship_err_name';
    }

    private static function valid(string $part): bool
    {
        $length = mb_strlen($part);

        return $length >= self::MIN && $length <= self::MAX && preg_match(self::PART, $part) === 1;
    }

    private static function squash(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
