<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries;

use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;

/** Цифри довідників для адмінки. */
final class DictionaryCounts
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    /** @return array<string, int> пункти за видами; один прохід по таблиці, а не по одному на вид */
    public function byKind(): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['kind', 'COUNT(*) AS n'])
            ->from(OfficeEntity::getTable())
            ->groupBy(['kind']);

        return self::fromRows($this->db->query($select) === false ? [] : $this->db->results());
    }

    public function cities(): int
    {
        return (int)$this->entityFactory->get(CityEntity::class)->count();
    }

    /**
     * @param iterable<object> $rows рядки `kind`, `n`
     * @return array<string, int> кожен вид, нуль, де немає рядка
     */
    public static function fromRows(iterable $rows): array
    {
        $counts = array_fill_keys(array_map(static fn(OfficeKind $kind): string => $kind->value, OfficeKind::cases()), 0);
        foreach ($rows as $row) {
            if (isset($counts[$row->kind])) {
                $counts[$row->kind] = (int)$row->n;
            }
        }

        return $counts;
    }
}
