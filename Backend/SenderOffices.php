<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;

/** Відділення, з якого магазин відправляє посилки: вибір за містом і перевірка індексу. */
class SenderOffices
{
    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * Лише відділення: поштомати й пункти PUDO у великому місті забили б список.
     *
     * @return list<array{postcode:string, title:string}>
     */
    public function forCity(int $cityId): array
    {
        if ($cityId <= 0) {
            return [];
        }
        $rows = $this->entityFactory->get(OfficeEntity::class)
            ->cols(['postcode', 'name_ua', 'address_ua'])
            ->noLimit()
            ->find(['city_id' => $cityId, 'kind' => OfficeKind::Office->value]) ?: [];

        return array_values(array_map(static fn(object $row): array => [
            'postcode' => (string)$row->postcode,
            'title' => trim($row->postcode . ' · ' . $row->name_ua . ($row->address_ua ? ', ' . $row->address_ua : '')),
        ], $rows));
    }

    /** Відправник здає посилки у відділенні: поштомат чи пункт PUDO не підходить, і в списку forCity() його немає. */
    public function exists(string $postcode): bool
    {
        return $this->entityFactory->get(OfficeEntity::class)->cols(['id'])
            ->findOne(['postcode' => $postcode, 'kind' => OfficeKind::Office->value]) !== false;
    }

    /** @return array{id:int, name:string, type:string, region:string}|null місто збереженого відділення відправника */
    public function cityOf(string $postcode): ?array
    {
        if ($postcode === '') {
            return null;
        }
        $cityId = (int)$this->entityFactory->get(OfficeEntity::class)->cols(['city_id'])->findOne(['postcode' => $postcode]);
        $city = $cityId > 0 ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;

        return $city ? [
            'id' => (int)$city->id,
            'name' => (string)$city->name_ua,
            'type' => (string)($city->type_ua ?? ''),
            'region' => (string)($city->region_ua ?? ''),
        ] : null;
    }
}
