<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\AmountDue;
use PHPUnit\Framework\TestCase;

/** Без модулів передоплати при отриманні стягується вся сума, але ніколи не від'ємна. */
class AmountDueTest extends TestCase
{
    public function testWholeTotalWithoutExtensions(): void
    {
        self::assertSame(2455.0, new AmountDue()->onPickup((object)['id' => 1], 2455.0));
    }

    public function testNeverNegative(): void
    {
        self::assertSame(0.0, new AmountDue()->onPickup((object)['id' => 1], -5.0));
    }
}
