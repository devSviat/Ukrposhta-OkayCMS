<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

/** Збережений вибір замовлення як рядки тексту — для сторінок замовлення (покупця й адмінки) та обох листів замовлення. */
final class OrderDeliveryView
{
    /**
     * Читає лише знімок замовлення, ніколи довідник: назви мають лишитися тими, що обрав покупець.
     *
     * @param float $orderDeliveryPrice поточна ціна доставки замовлення: менеджер міг задати її після оформлення
     * @param bool $codNow оплата замовлення зараз — післяплата (покупець може змінити її на сторінці замовлення)
     * @param bool $pricePublic спосіб доставки показує ціну покупцям; магазин бачить комісію в будь-якому разі
     * @return array{city:string, point:string, priceUnconfirmed:bool, codFee:?float, codFeePublic:bool}
     */
    public static function lines(object $row, float $orderDeliveryPrice, bool $codNow, bool $pricePublic): array
    {
        $point = array_filter(
            [trim((string)($row->office_postcode ?? '') . ' ' . (string)($row->office_title ?? '')), (string)($row->office_address ?? '')],
            static fn(string $part): bool => $part !== '',
        );

        return [
            'city' => (string)($row->city_name ?? ''),
            'point' => implode(', ', $point),
            // Укрпошта не дала ціни під час оформлення: доставку не нараховано, її вказує менеджер.
            'priceUnconfirmed' => ($row->price_source ?? null) === 'fallback' && !($orderDeliveryPrice > 0),
            // Сплачує отримувач у відділенні, не входить у суму замовлення.
            'codFee' => $codNow && (float)($row->cod_fee ?? 0) > 0 ? round((float)$row->cod_fee, 2) : null,
            'codFeePublic' => $pricePublic,
        ];
    }
}
