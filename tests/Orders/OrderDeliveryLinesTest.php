<?php

namespace Modules\Sviat\Ukrposhta\Orders;

require_once __DIR__ . '/../Support/OrderWorld.php';

use Modules\Sviat\Ukrposhta\Support\OrderWorld;
use PHPUnit\Framework\TestCase;

/** Знімок пункту лишається в базі, коли менеджер змінив доставку замовлення на іншу, але не показується. */
class OrderDeliveryLinesTest extends TestCase
{
    use OrderWorld;

    public function testOwnDeliveryShowsThePoint(): void
    {
        $this->ownOrder();

        $lines = $this->lines()->forOrder($this->order);

        self::assertSame('село Лісове', $lines['city']);
        self::assertSame('23222 Лісове, вул. Центральна, 3', $lines['point']);
        self::assertSame(21.5, $lines['codFee']);
    }

    public function testSwitchedDeliveryShowsNothing(): void
    {
        $this->switchedOrder();

        self::assertNull($this->lines()->forOrder($this->order));
        self::assertNull($this->lines()->ownDelivery($this->order));
    }

    public function testOwnDeliveryWithoutSnapshotShowsNothing(): void
    {
        $this->ownOrder();
        $this->snapshot = null;

        self::assertNull($this->lines()->forOrder($this->order));
        self::assertNotNull($this->lines()->ownDelivery($this->order));
    }
}
