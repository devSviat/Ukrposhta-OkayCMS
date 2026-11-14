<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

/** Відповідь на зміну менеджером пункту видачі замовлення. */
final readonly class OrderPointResult
{
    /**
     * @param ?string $error мовний ключ (без префікса модуля) причини, чому нічого не збережено
     * @param ?string $warning мовний ключ того, що менеджер має знати про збережений пункт
     * @param ?array{action: string, price: ?float} $price що сталося з ціною доставки
     */
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?string $warning = null,
        public ?array $price = null,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
