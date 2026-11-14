<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;

final class MassOrderSource implements MassOrders
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $lines,
        private readonly ShipmentDrafts $drafts,
    ) {
    }

    public function isOurs(int $orderId): bool
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);

        return is_object($order) && $this->lines->ownDelivery($order) !== null;
    }

    public function draft(int $orderId): ShipmentDraft|string
    {
        return $this->drafts->forOrder($orderId);
    }
}
