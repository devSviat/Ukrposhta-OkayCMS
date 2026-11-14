<?php

namespace Okay\Modules\Sviat\Ukrposhta\Payments;

/** Блок "Післяплата Укрпошти" на сторінці способу оплати ядра (payment_cod.tpl). */
final class CodPaymentInput
{
    /** Приховане поле блоку: без нього POST прийшов з іншого місця, і колонку лишаємо як є. */
    public const string MARKER = 'sviat_up_cod_form';
    public const string SWITCH = 'sviat_up_cod';

    /**
     * @param array<string, mixed> $post
     * @param mixed $module надісланий модуль оплати; онлайн-оплата ніколи не є післяплатою
     * @return int|null прапорець для збереження або null, щоб не чіпати колонку
     */
    public static function flag(array $post, mixed $module): ?int
    {
        if (($post[self::MARKER] ?? null) !== '1') {
            return null;
        }

        return CodPayments::isManual($module) && ($post[self::SWITCH] ?? null) === '1' ? 1 : 0;
    }
}
