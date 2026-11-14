<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

/** Визначає, для чого зроблено розрахунок ціни; будь-яка зміна кошика дає новий ключ, тож застаріла ціна не показується. */
final class QuoteKey
{
    /** Збільшується, коли змінюється зміст закешованого розрахунку ціни. */
    private const string FORMAT = 'v1';

    public static function for(int $deliveryId, int $officeId, bool $cod, object $cart): string
    {
        $lines = [];
        foreach ((array)($cart->purchases ?? []) as $purchase) {
            // Вага варіанта: адміністратор може змінити її, поки ціна лежить у кеші сесії.
            $variant = $purchase->variant ?? null;
            $lines[] = (int)($purchase->variant_id ?? $variant->id ?? 0) . ':' . (int)($purchase->amount ?? 0) . ':' . (float)($variant->weight ?? 0);
        }
        sort($lines);

        return sha1(implode('|', [self::FORMAT, $deliveryId, $officeId, $cod ? 1 : 0, implode(',', $lines), number_format((float)($cart->total_price ?? 0), 2, '.', '')]));
    }
}
