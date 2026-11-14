<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/**
 * Стан рядка відправлення. `pending` — резервація до запиту, `uncertain` — запит пішов, а відповіді
 * немає, `created` — відправлення з uuid і штрихкодом.
 */
final class PendingShipment
{
    public const string PENDING = 'pending';
    public const string UNCERTAIN = 'uncertain';
    public const string CREATED = 'created';

    /**
     * Створення — до п'яти викликів по 30 с. Резервація, старша за 10 хвилин, лишилася від процесу, що
     * помер, і він міг устигнути надіслати POST shipments: такий рядок — невідомий, а не вільний, інакше
     * повтор створив би друге відправлення.
     */
    public const int STALE_SECONDS = 600;

    /** Рядок без відправлення: резервація або невідомий результат. */
    public static function is(?object $row): bool
    {
        return $row !== null && ($row->state ?? null) !== self::CREATED;
    }

    /** Створення ще виконується в іншому запиті. */
    public static function running(?object $row, int $now): bool
    {
        return self::is($row) && !self::needsCheck($row, $now);
    }

    /** Відправлення могло з'явитися в Укрпошті: нове не створюється, доки його не знайдено чи не прив'язано. */
    public static function needsCheck(?object $row, int $now): bool
    {
        if (!self::is($row)) {
            return false;
        }
        if (($row->state ?? null) === self::UNCERTAIN) {
            return true;
        }
        $reservedAt = strtotime((string)($row->reserved_at ?? $row->created_at ?? ''));

        return $reservedAt === false || $now - $reservedAt >= self::STALE_SECONDS;
    }
}
