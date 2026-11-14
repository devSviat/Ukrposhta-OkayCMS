<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

/** Які замовлення ще є в базі; null — запит не вдався, і відповіді немає. */
interface ExistingOrders
{
    /**
     * @param list<int> $orderIds
     * @return ?array<int, true>
     */
    public function among(array $orderIds): ?array;

    public function maxId(): ?int;
}
