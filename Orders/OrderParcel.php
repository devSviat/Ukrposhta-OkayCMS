<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Core\EntityFactory;
use Okay\Entities\PurchasesEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;

/** Товари замовлення як посилка: те саме, що кошик для кошика, — для замовлення в адмінці. */
final class OrderParcel
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly UahTotal $uahTotal,
    ) {
    }

    /** Замовлення у формі кошика, яку рахують CartQuotes і UahTotal. */
    public function cart(object $order): object
    {
        return self::asCart($order, $this->purchases((int)$order->id));
    }

    public function metrics(object $order): CartMetrics
    {
        return $this->uahTotal->metrics($this->cart($order));
    }

    /** @return list<object> покупки, кожна зі своїм `variant` (weight) */
    private function purchases(int $orderId): array
    {
        $purchases = array_values($this->entityFactory->get(PurchasesEntity::class)->noLimit()->find(['order_id' => $orderId]));
        $variantIds = array_values(array_filter(array_map(static fn($p): int => (int)$p->variant_id, $purchases)));
        $variants = $variantIds === [] ? [] : $this->entityFactory->get(VariantsEntity::class)->mappedBy('id')->noLimit()->find(['id' => $variantIds]);
        foreach ($purchases as $purchase) {
            $purchase->variant = $variants[(int)$purchase->variant_id] ?? null;
        }

        return $purchases;
    }

    /** Як і в кошика, сума — лише товари, без доставки: від неї залежать безкоштовна доставка й ліміти пунктів. */
    public static function asCart(object $order, array $purchases): object
    {
        $total = (float)($order->total_price ?? 0);
        $goods = empty($order->separate_delivery) ? $total - (float)($order->delivery_price ?? 0) : $total;

        return (object)['purchases' => $purchases, 'total_price' => max(0.0, $goods)];
    }
}
