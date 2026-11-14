<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store;

/**
 * Масові записи рядків довідників. $table — 'offices' або 'cities'.
 * Обидві таблиці мають `region_id`: область — одиниця заміни, бо класифікатор віддає пункти
 * лише по одній області. Кожен метод кидає виняток на невдалому запиті: тихо провалений запис,
 * а за ним deleteNotSeen(), стерли б рядки, які ще дійсні.
 */
interface DictionaryStore
{
    public const string OFFICES = 'offices';
    public const string CITIES = 'cities';

    /** @return array<int, string> id → row_hash рядків області */
    public function hashes(string $table, int $regionId): array;

    /** @param list<array> $rows вставлені або замінені разом із `region_id` */
    public function upsert(string $table, array $rows, int $run): void;

    /** Позначає незмінені рядки як присутні в запуску $run. @param list<int> $ids */
    public function touch(string $table, array $ids, int $run): void;

    /** Видаляє рядки області, яких запуск $run не побачив; викликати лише після правдоподібного обходу області. */
    public function deleteNotSeen(string $table, int $regionId, int $run): int;
}
