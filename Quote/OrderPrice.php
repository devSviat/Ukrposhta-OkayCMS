<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

/**
 * Ціна доставки замовлення під час оформлення. Те, з чим порівнюється результат ядра, береться з рядка
 * способу доставки в базі: PriceDecision вважає будь-яку іншу ціну рішенням стороннього модуля, тож значення
 * з форми тут дало б покупцю вибирати ціну самому.
 */
final class OrderPrice
{
    /**
     * @param array $coreInfo результат DeliveriesHelper::prepareDeliveryPriceInfo після попередніх розширень
     * @param object $delivery рядок DeliveriesEntity
     * @param object $order рядок OrdersEntity
     * @return array{info: array, source: ?string}
     */
    public static function decide(?DeliveryQuote $quote, array $coreInfo, object $delivery, object $order): array
    {
        $freeByCore = FreeDelivery::applies($delivery, (float)($order->total_price ?? 0));

        return PriceDecision::forOrder($quote, $coreInfo, $freeByCore, $freeByCore ? 0.0 : (float)($delivery->price ?? 0));
    }
}
