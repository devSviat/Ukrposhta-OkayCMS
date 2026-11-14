<?php

namespace Okay\Modules\Sviat\Ukrposhta\Payments;

use Okay\Core\EntityFactory;
use Okay\Entities\PaymentsEntity;

/**
 * Способи оплати, позначені "післяплата Укрпошти". Вибір такого способу в кошику — це вибір
 * покупцем післяплати; окремого чекбокса в кошику немає.
 */
final class CodPayments
{
    /** Власне поле в таблиці оплат ядра. */
    public const string FIELD = 'sviat__ukrposhta__cod';

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /** "Manual" у селекті типу оплати: ядро зберігає його як рядок 'null'. */
    public static function isManual(mixed $module): bool
    {
        return $module === null || $module === '' || $module === 'null';
    }

    /** @return list<object> способи оплати, які кошик вважає післяплатою Укрпошти */
    public function flagged(): array
    {
        $methods = $this->entityFactory->get(PaymentsEntity::class)->noLimit()->find() ?: [];

        return array_values(array_filter($methods, static fn(object $method): bool => self::isManual($method->module ?? null) && (int)($method->{self::FIELD} ?? 0) === 1));
    }

    /** Онлайн-оплати (будь-який модуль оплати) ніколи не є післяплатою, хоч би що було в колонці. */
    public function isCod(int $paymentId): bool
    {
        if ($paymentId <= 0) {
            return false;
        }
        $method = $this->entityFactory->get(PaymentsEntity::class)->get($paymentId);

        return $method && self::isManual($method->module ?? null) && (int)($method->{self::FIELD} ?? 0) === 1;
    }
}
