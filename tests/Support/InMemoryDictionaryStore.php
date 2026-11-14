<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;

final class InMemoryDictionaryStore implements DictionaryStore
{
    /** @var array<string, array<int, array>> */
    public array $tables = ['offices' => [], 'cities' => []];

    public function hashes(string $table, int $regionId): array
    {
        $hashes = [];
        foreach ($this->tables[$table] as $id => $row) {
            if ($row['region_id'] === $regionId) {
                $hashes[$id] = $row['row_hash'];
            }
        }

        return $hashes;
    }

    public function upsert(string $table, array $rows, int $run): void
    {
        foreach ($rows as $row) {
            $this->tables[$table][$row['id']] = ['seen_run' => $run] + $row;
        }
    }

    public function touch(string $table, array $ids, int $run): void
    {
        foreach ($ids as $id) {
            $this->tables[$table][$id]['seen_run'] = $run;
        }
    }

    public function deleteNotSeen(string $table, int $regionId, int $run): int
    {
        $before = count($this->tables[$table]);
        $this->tables[$table] = array_filter(
            $this->tables[$table],
            static fn(array $row): bool => $row['region_id'] !== $regionId || $row['seen_run'] === $run,
        );

        return $before - count($this->tables[$table]);
    }

    /** @return array<int, array> */
    public function region(string $table, int $regionId): array
    {
        return array_filter($this->tables[$table], static fn(array $row): bool => $row['region_id'] === $regionId);
    }
}
