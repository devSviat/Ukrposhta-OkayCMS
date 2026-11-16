<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store;

use Aura\SqlQuery\QueryInterface;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;

/** Масові записи через QueryFactory: порядкові записи через Entity на ~12k пунктів тривали б хвилини. */
final class DbDictionaryStore implements DictionaryStore
{
    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    public function hashes(string $table, int $regionId): array
    {
        $select = $this->queryFactory->newSelect()->cols(['id', 'row_hash'])->from(self::table($table))
            ->where('region_id = :region')->bindValue('region', $regionId);
        $this->run($select);
        $hashes = [];
        foreach ($this->db->results() as $row) {
            $hashes[(int)$row->id] = (string)$row->row_hash;
        }

        return $hashes;
    }

    public function upsert(string $table, array $rows, int $run): void
    {
        if ($rows === []) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $rows = array_map(static fn(array $row): array => ['seen_run' => $run, 'updated_at' => $now] + $row, $rows);

        $insert = $this->queryFactory->newInsert()->into(self::table($table))->addRows($rows);
        foreach (array_keys($rows[0]) as $column) {
            if ($column !== 'id') {
                $insert->onDuplicateKeyUpdate($column, "VALUES({$column})");
            }
        }
        // Aura addRows() добудовує останній рядок пакета лише під час збирання тексту, а Database::query()
        // OkayCMS 4.5.3 читає прив'язки раніше: пакет із двох рядків губив значення другого.
        // У форку ядро виправлене (devSviat/OkayCMS-Fork#437); виклик лишається для OkayCMS 4.5.3.
        $insert->getStatement();
        $this->run($insert);
    }

    public function touch(string $table, array $ids, int $run): void
    {
        if ($ids === []) {
            return;
        }
        $update = $this->queryFactory->newUpdate()->table(self::table($table))
            ->set('seen_run', ':run')->where('id IN (:ids)')
            ->bindValues(['run' => $run, 'ids' => $ids]);
        $this->run($update);
    }

    public function deleteNotSeen(string $table, int $regionId, int $run): int
    {
        $delete = $this->queryFactory->newDelete()->from(self::table($table))
            ->where('region_id = :region')->where('seen_run <> :run')
            ->bindValues(['region' => $regionId, 'run' => $run]);
        $this->run($delete);

        return (int)$this->db->affectedRows();
    }

    /** Database::query() логує й ковтає помилки SQL; запуск довідників натомість мусить зупинитися. */
    private function run(QueryInterface $query): void
    {
        if ($this->db->query($query) === false) {
            throw new \RuntimeException('Ukrposhta dictionary query failed; see the application log');
        }
    }

    private static function table(string $table): string
    {
        return match ($table) {
            self::OFFICES => OfficeEntity::getTable(),
            self::CITIES => CityEntity::getTable(),
            default => throw new \InvalidArgumentException("Unknown dictionary table {$table}"),
        };
    }
}
