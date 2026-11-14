<?php

namespace Modules\Sviat\Ukrposhta\Orders;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Orders\ExistingOrders;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderRowsCleanup;
use PHPUnit\Framework\TestCase;

/** Знімки — єдине місце, де лишається пункт замовлення: збій запиту до замовлень не повинен їх стерти. */
class OrderRowsCleanupTest extends TestCase
{
    private const string TODAY = '2026-11-14';

    /** @var list<int>|null */
    private ?array $deleted = null;
    private InMemoryState $state;

    private function tick(?array $existing, ?int $maxId): void
    {
        $this->state = new InMemoryState();
        $entity = $this->createStub(OrderDeliveryEntity::class);
        $entity->method('noLimit')->willReturnSelf();
        $entity->method('cols')->willReturnSelf();
        $entity->method('find')->willReturn([
            (object)['id' => 5, 'order_id' => 10],
            (object)['id' => 6, 'order_id' => 11],
            (object)['id' => 7, 'order_id' => 40],
        ]);
        $entity->method('delete')->willReturnCallback(function ($ids): bool {
            $this->deleted = array_values((array)$ids);

            return true;
        });
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);
        $orders = new class ($existing, $maxId) implements ExistingOrders {
            public function __construct(private ?array $existing, private ?int $max)
            {
            }

            public function among(array $orderIds): ?array
            {
                return $this->existing;
            }

            public function maxId(): ?int
            {
                return $this->max;
            }
        };

        new OrderRowsCleanup($factory, $this->state, $orders, static fn(): string => self::TODAY)->tick();
    }

    public function testRowsOfDeletedOrdersGo(): void
    {
        $this->tick([10 => true, 40 => true], 50);

        self::assertSame([6], $this->deleted);
        self::assertSame(self::TODAY, $this->state->get('orphan_rows_checked_on'));
    }

    public function testFailedLookupKeepsEveryRowAndRetries(): void
    {
        $this->tick(null, 50);

        self::assertNull($this->deleted);
        self::assertNull($this->state->get('orphan_rows_checked_on'), 'the next tick tries again');
    }

    public function testEmptyLookupKeepsEveryRow(): void
    {
        $this->tick([], 50);

        self::assertNull($this->deleted);
    }

    public function testFailedMaxIdKeepsEveryRow(): void
    {
        $this->tick([10 => true], null);

        self::assertNull($this->deleted);
    }

    /** Замовлення з номером, більшим за найбільший видимий, ще не видно, а не видалено. */
    public function testOrderAboveTheMaxIdIsKept(): void
    {
        $this->tick([10 => true], 20);

        self::assertSame([6], $this->deleted);
    }
}
