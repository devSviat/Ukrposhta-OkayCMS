<?php

namespace Okay\Modules\Sviat\Ukrposhta\Integration;

/** `group` — ключ групи статусів Укрпошти (created, accepted, in_transit, problem, arrived, storage, received, returning, returned, cancelled). */
final readonly class UpShipment
{
    public function __construct(
        public int $orderId,
        public string $barcode,
        public ?string $group,
        public ?string $statusAt,
        public ?string $arrivedAt,
        public bool $cod,
    ) {
    }
}
