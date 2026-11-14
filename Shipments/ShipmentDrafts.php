<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderParcel;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\Parcel;

/** Будує першу чернетку відправлення замовлення. */
final class ShipmentDrafts
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $deliveryLines,
        private readonly OrderDeliveryStore $snapshots,
        private readonly OrderParcel $parcel,
        private readonly UahTotal $uahTotal,
        private readonly CodPayments $codPayments,
        private readonly Settings $settings,
        private readonly AmountDue $amountDue,
    ) {
    }

    /** @return ShipmentDraft|string чернетка або мовний ключ того, чого бракує замовленню */
    public function forOrder(int $orderId): ShipmentDraft|string
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        if (!$order) {
            return 'sviat__ukrposhta__ship_err_no_order';
        }
        if ($this->deliveryLines->ownDelivery($order) === null) {
            return 'sviat__ukrposhta__ship_err_not_own';
        }
        $snapshot = $this->snapshots->forOrder($orderId);
        if ($snapshot === null || (string)($snapshot->office_postcode ?? '') === '') {
            return 'sviat__ukrposhta__ship_err_no_point';
        }

        $cart = $this->parcel->cart($order);
        $metrics = $this->uahTotal->metrics($cart);
        $orderTotalUah = $this->uahTotal->metrics((object)['purchases' => [], 'total_price' => (float)($order->total_price ?? 0)])->totalUah;
        if ($metrics->totalUah === null || $orderTotalUah === null) {
            return 'sviat__ukrposhta__ship_err_no_uah';
        }

        return self::build(
            $order,
            $snapshot,
            ShipmentConfig::fromSettings($this->settings->get(...)),
            $metrics,
            $orderTotalUah,
            $this->codPayments->isCod((int)($order->payment_method_id ?? 0)),
            fn(float $totalUah): float => $this->amountDue->onPickup($order, $totalUah),
            $cart->purchases,
        );
    }

    /**
     * @param float $orderTotalUah сума замовлення з доставкою (коли вона в сумі), у гривнях
     * @param bool $paymentIsCod спосіб оплати замовлення — післяплата
     * @param ?\Closure(float): float $amountDue що стягує Укрпошта, із суми замовлення в гривнях
     * @param list<object> $purchases покупки замовлення, з них складається опис у режимі «товари»
     */
    public static function build(
        object $order,
        object $snapshot,
        ShipmentConfig $config,
        CartMetrics $metrics,
        float $orderTotalUah,
        bool $paymentIsCod,
        ?\Closure $amountDue = null,
        array $purchases = [],
    ): ShipmentDraft {
        $parcel = Parcel::fromMetrics($metrics, $config->shipping);
        // Що лишає стягнути інший модуль (передоплата); якщо нічого не лишилось, післяплати немає, ніколи не «0» чи від'ємна.
        $due = max(0.0, round($amountDue ? $amountDue($orderTotalUah) : $orderTotalUah, 2));
        // Оплачене замовлення гроші при отриманні не збирає.
        $cod = $paymentIsCod && empty($order->paid) && $due > 0;
        $codAmount = $cod ? $due : 0.0;
        // Оголошена цінність — товари, але не менша за післяплату: Укрпошта відхиляє postPay більший за неї,
        // а в сумі замовлення вже є доставка.
        $declared = round(max((float)$metrics->totalUah, $codAmount), 2);

        return new ShipmentDraft(
            $config->shipping->type,
            $parcel->weightGrams,
            $parcel->lengthCm,
            $parcel->widthCm,
            $parcel->heightCm,
            $declared,
            $cod,
            $codAmount,
            $config->payerIsRecipient,
            $config->codPayerIsRecipient,
            $config->onFail,
            ShipmentDescription::forOrder($config->descriptionMode, $config->description, (int)$order->id, $purchases),
            $config->sms,
            PersonName::fromOrder($order),
            ShipmentDraft::phone((string)($order->phone ?? '')),
            (string)$snapshot->office_postcode,
            (string)($snapshot->office_kind ?? ''),
            $paymentIsCod,
        );
    }
}
