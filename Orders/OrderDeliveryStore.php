<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelection;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;

final class OrderDeliveryStore
{
    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * Назви копіюються: замовлення зберігає те, що обрали, навіть якщо довідник зміниться.
     *
     * @param array{price?: mixed, price_source?: ?string, cod: int, cod_fee: ?float}|null $pricing ключ, якого немає, лишає збережене значення
     */
    public function save(int $orderId, CartSelection $selection, ?array $pricing = null): void
    {
        if (!$selection->applies() || $selection->delivery === null) {
            return;
        }
        $entity = $this->entityFactory->get(OrderDeliveryEntity::class);
        $row = ($pricing ?? []) + self::row($orderId, $selection);
        $existing = $entity->findOne(['order_id' => $orderId]);
        $existing ? $entity->update((int)$existing->id, $row) : $entity->add($row + ['created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> */
    public static function row(int $orderId, CartSelection $selection): array
    {
        $city = $selection->city;
        $office = $selection->office;

        return [
            'order_id' => $orderId,
            'delivery_id' => (int)$selection->delivery?->id,
            'city_id' => (int)$city?->id,
            'city_name' => self::cityName($city),
            'office_id' => (int)$office?->id,
            'office_postcode' => (string)($office->postcode ?? ''),
            'office_kind' => (string)($office->kind ?? ''),
            'office_title' => (string)($office->name_ua ?? ''),
            'office_address' => ($office->address_ua ?? '') !== '' ? (string)$office->address_ua : null,
        ];
    }

    /** Тип, назва, район і область: у тезок однакова назва, тож замовлення зберігає, котрий із них. */
    public static function cityName(?object $city): string
    {
        if ($city === null) {
            return '';
        }
        $name = trim((string)($city->type_ua ?? '') . ' ' . (string)($city->name_ua ?? ''));
        $parts = array_filter([$name, (string)($city->district_ua ?? ''), (string)($city->region_ua ?? '')], static fn(string $part): bool => $part !== '');

        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /** Ціна доставки, яку менеджер задав в адмінці. */
    public function setPrice(int $orderId, float $price, string $source): void
    {
        $entity = $this->entityFactory->get(OrderDeliveryEntity::class);
        $row = $entity->findOne(['order_id' => $orderId]);
        if (is_object($row)) {
            $entity->update((int)$row->id, ['price' => $price, 'price_source' => $source]);
        }
    }

    public function forOrder(int $orderId): ?object
    {
        return $this->entityFactory->get(OrderDeliveryEntity::class)->findOne(['order_id' => $orderId]) ?: null;
    }

    /** Останній вибір покупця, який знову пропонується в кошику. */
    public function lastForUser(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }
        $orderIds = array_keys($this->entityFactory->get(OrdersEntity::class)->mappedBy('id')->cols(['id'])->find(['user_id' => $userId, 'limit' => 20]));
        if ($orderIds === []) {
            return null;
        }
        $rows = $this->entityFactory->get(OrderDeliveryEntity::class)->find(['order_id' => $orderIds, 'limit' => 1]);

        return $rows ? reset($rows) : null;
    }
}
