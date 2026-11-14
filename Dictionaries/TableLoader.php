<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;

/** Замінює рядки однієї області: записує пакетами лише змінені рядки, решту позначає побаченими, і рахує, що сталося. */
final class TableLoader
{
    private const int BATCH = 500;

    /** @var array<int, string> */
    private array $hashes;
    /** @var list<array> */
    private array $pendingRows = [];
    /** @var list<int> */
    private array $pendingTouch = [];
    private int $added = 0;
    private int $changed = 0;
    private int $unchanged = 0;
    private readonly int $storedAtStart;

    public function __construct(
        private readonly DictionaryStore $store,
        private readonly string $table,
        private readonly int $regionId,
        private readonly int $run,
    ) {
        $this->hashes = $store->hashes($table, $regionId);
        $this->storedAtStart = count($this->hashes);
    }

    public function storedAtStart(): int
    {
        return $this->storedAtStart;
    }

    /**
     * Класифікатор під навантаженням відповідає 200 з порожнім або урізаним списком: така відповідь не
     * повинна видалити область. Зникнення понад половини пунктів за тиждень правдоподібне лише як збій.
     * Порожня область без збережених рядків — не збій: видаляти нічого, а окупована область
     * інакше тримала б кожен запуск у стані partial.
     */
    public static function assertPlausibleRegion(int $before, int $after): void
    {
        if ($before === 0 && $after === 0) {
            return;
        }
        if ($after === 0 || $after < (int)floor($before / 2)) {
            throw new ImplausibleRegion("{$after} points against {$before} stored; the region keeps its rows");
        }
    }

    public function put(array $row): void
    {
        $known = $this->hashes[$row['id']] ?? null;
        if ($known === $row['row_hash']) {
            $this->unchanged++;
            $this->pendingTouch[] = $row['id'];
            if (count($this->pendingTouch) >= self::BATCH) {
                $this->flushTouch();
            }

            return;
        }

        $known === null ? $this->added++ : $this->changed++;
        $this->hashes[$row['id']] = $row['row_hash'];
        $this->pendingRows[] = $row;
        if (count($this->pendingRows) >= self::BATCH) {
            $this->flushRows();
        }
    }

    /** Видаляє рядки області, яких цей обхід не бачив: викликати лише після assertPlausibleRegion(). */
    public function finish(): UpdateCounts
    {
        $this->flushRows();
        $this->flushTouch();
        $deleted = $this->store->deleteNotSeen($this->table, $this->regionId, $this->run);

        return new UpdateCounts($this->added, $this->changed, $deleted, $this->unchanged);
    }

    private function flushRows(): void
    {
        if ($this->pendingRows !== []) {
            $this->store->upsert($this->table, $this->pendingRows, $this->run);
            $this->pendingRows = [];
        }
    }

    private function flushTouch(): void
    {
        if ($this->pendingTouch !== []) {
            $this->store->touch($this->table, $this->pendingTouch, $this->run);
            $this->pendingTouch = [];
        }
    }
}
