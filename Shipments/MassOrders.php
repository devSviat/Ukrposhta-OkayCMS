<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Що масовому створенню треба знати про замовлення. */
interface MassOrders
{
    /** Замовлення існує й доставляється цим модулем. */
    public function isOurs(int $orderId): bool;

    /** @return ShipmentDraft|string чернетка або мовний ключ того, чого бракує замовленню */
    public function draft(int $orderId): ShipmentDraft|string;
}
