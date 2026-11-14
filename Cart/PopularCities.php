<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/** Кнопки міст над полем міста в кошику: їх обирає й упорядковує лише менеджер. */
final class PopularCities
{
    public const int MAX = 20;
    private const string STATE = 'popular_cities';

    public function __construct(
        private readonly StateStorage $state,
        private readonly EntityFactory $entityFactory,
    ) {
    }

    /** @param list<int|string> $ids у порядку, який задав менеджер */
    public function save(array $ids): void
    {
        $wanted = array_values(array_filter(array_map(intval(...), $ids), static fn(int $id): bool => $id > 0));
        $existing = $wanted === [] ? [] : array_map(intval(...), array_keys(
            $this->entityFactory->get(CityEntity::class)->mappedBy('id')->cols(['id'])->noLimit()->find(['id' => $wanted])
        ));
        self::store($this->state, self::clean($ids, $existing));
    }

    /** @return list<array{id:int, name:string, type:string, region:string}> */
    public function list(): array
    {
        $ids = self::stored($this->state);
        if ($ids === []) {
            return [];
        }
        $rows = $this->entityFactory->get(CityEntity::class)->mappedBy('id')
            ->cols(['id', 'name_ua', 'type_ua', 'region_ua'])->noLimit()->find(['id' => $ids]);
        $list = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $list[] = [
                    'id' => $id,
                    'name' => (string)$rows[$id]->name_ua,
                    'type' => (string)$rows[$id]->type_ua,
                    'region' => (string)$rows[$id]->region_ua,
                ];
            }
        }

        return $list;
    }

    /** @return list<array{id:int, label:string, type:string}> кнопки для кошика */
    public function buttons(): array
    {
        return array_map(self::button(...), $this->list());
    }

    /**
     * @param array{id:int, name:string, type:string} $city
     * @return array{id:int, label:string, type:string}
     */
    public static function button(array $city): array
    {
        return ['id' => $city['id'], 'label' => $city['name'], 'type' => $city['type']];
    }

    /**
     * @param list<mixed> $raw
     * @param list<int> $existingIds
     * @return list<int>
     */
    public static function clean(array $raw, array $existingIds): array
    {
        $clean = [];
        foreach ($raw as $id) {
            $id = is_numeric($id) ? (int)$id : 0;
            if ($id > 0 && in_array($id, $existingIds, true) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return array_slice($clean, 0, self::MAX);
    }

    /** @return list<int> */
    public static function stored(StateStorage $state): array
    {
        $ids = json_decode((string)$state->get(self::STATE), true);

        return is_array($ids) && array_is_list($ids) ? array_values(array_filter(array_map(intval(...), $ids))) : [];
    }

    /** @param list<int> $ids */
    public static function store(StateStorage $state, array $ids): void
    {
        $state->set(self::STATE, json_encode($ids));
    }
}
