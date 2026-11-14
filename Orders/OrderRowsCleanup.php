<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/** Рядки модуля для замовлень, які видаляє адмінка. */
final class OrderRowsCleanup
{
    private const string CHECKED_ON = 'orphan_rows_checked_on';

    private readonly \Closure $today;

    /** @param ?\Closure(): string $today Y-m-d */
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly StateStorage $state,
        private readonly ExistingOrders $orders,
        ?\Closure $today = null,
    ) {
        $this->today = $today ?? static fn(): string => date('Y-m-d');
    }

    /**
     * Планувальник: раз на день — рядки замовлень, видалених, поки модуль був вимкнений (хук видалення не спрацював).
     * Повторно використаний номер замовлення інакше показав би пункт іншого покупця.
     */
    public function tick(): void
    {
        $today = ($this->today)();
        if ($this->state->get(self::CHECKED_ON) === $today) {
            return;
        }
        $entity = $this->entityFactory->get(OrderDeliveryEntity::class);
        $orderOf = [];
        foreach ($entity->noLimit()->cols(['id', 'order_id'])->find() as $row) {
            $orderOf[(int)$row->id] = (int)$row->order_id;
        }
        $orphans = $orderOf === [] ? [] : self::orphans($orderOf, $this->orders->among(array_values(array_unique($orderOf))), $this->orders->maxId());
        // Без відповіді про замовлення не видаляємо нічого й не позначаємо день: наступний тік спробує знову.
        if ($orphans === null) {
            return;
        }
        if ($orphans !== []) {
            $entity->delete($orphans);
        }
        $this->state->set(self::CHECKED_ON, $today);
    }

    /**
     * Знімок без замовлення — сирота лише тоді, коли відповідь про замовлення є й правдоподібна. Порожня відповідь
     * при наявних знімках схожа радше на збій, ніж на видалення всіх замовлень; номер, більший за найбільший
     * у таблиці, належить замовленню, яке ще не видно (не видалене).
     *
     * @param array<int, int> $orderOf order_id за id рядка
     * @param ?array<int, true> $existing
     * @return ?list<int> id рядків для видалення; null — відповіді немає або вона неправдоподібна
     */
    public static function orphans(array $orderOf, ?array $existing, ?int $maxOrderId): ?array
    {
        if ($existing === null || $existing === [] || $maxOrderId === null || $maxOrderId <= 0) {
            return null;
        }

        return array_keys(array_filter(
            $orderOf,
            static fn(int $orderId): bool => !isset($existing[$orderId]) && $orderId <= $maxOrderId,
        ));
    }

    /** @param list<int|string> $orderIds */
    public function forOrders(array $orderIds): void
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds))));
        if ($orderIds === []) {
            return;
        }
        $entity = $this->entityFactory->get(OrderDeliveryEntity::class);
        $ids = array_map(intval(...), $entity->noLimit()->cols(['id'])->find(['order_id' => $orderIds]));
        if ($ids !== []) {
            $entity->delete($ids);
        }
    }
}
