<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/**
 * Групи статусів відправлення для бейджів і фільтра замовлень. Подія трекінгу точніша за lifecycle eCom,
 * тому коли подія відома, група береться з неї, а lifecycle лишається для відправлень, яких трекінг ще не бачив.
 */
final class ShipmentStatus
{
    /** Подія 41000 з цією причиною означає «вручено відправнику», тобто повернення. */
    public const int RETURNED_REASON = 10;

    private const array EVENT_GROUPS = [
        'created' => [10601],
        'accepted' => [10100],
        'in_transit' => [20700, 20800, 20900, 21500, 31300],
        'problem' => [31100, 31400],
        'arrived' => [21700],
        'storage' => [21400],
        'returning' => [31200],
        'cancelled' => [10600, 10602, 10603],
    ];

    /** Вручення: група залежить від причини, тож у таблицю подій не входить. */
    public const array HANDOVER_EVENTS = [41000, 48000];

    private const array LIFECYCLE_GROUPS = [
        'CREATED' => 'created',
        'REGISTERED' => 'accepted',
        'DELIVERING' => 'in_transit',
        'FORWARDING' => 'in_transit',
        'IN_DEPARTMENT' => 'arrived',
        'STORAGE' => 'storage',
        'DELIVERED' => 'received',
        'RETURNING' => 'returning',
        'RETURNED' => 'returned',
        'CANCELED' => 'cancelled',
        'DELETED' => 'cancelled',
    ];

    /** З відправленням більше нічого не станеться: трекінг його не опитує. */
    public const array FINAL = ['received', 'returned', 'cancelled'];

    /** Групи фільтра списку замовлень з мовним ключем назви; `none` — замовлення без відправлення. */
    public const array FILTER_GROUPS = [
        'created' => 'sviat__ukrposhta__status_created',
        'accepted' => 'sviat__ukrposhta__status_accepted',
        'in_transit' => 'sviat__ukrposhta__status_in_transit',
        'problem' => 'sviat__ukrposhta__status_problem',
        'arrived' => 'sviat__ukrposhta__status_arrived',
        'storage' => 'sviat__ukrposhta__status_storage',
        'received' => 'sviat__ukrposhta__status_received',
        'returning' => 'sviat__ukrposhta__status_returning',
        'returned' => 'sviat__ukrposhta__status_returned',
        'cancelled' => 'sviat__ukrposhta__status_cancelled',
        'none' => 'sviat__ukrposhta__status_none',
    ];

    /**
     * @param ?string $previous група до цієї події: вручення після `returning` — це повернення, навіть коли
     *                          Укрпошта не вказала причину 10
     * @return ?string null, коли про відправлення нічого не відомо
     */
    public static function group(?int $event, ?string $lifecycle, ?int $reason, ?string $previous = null): ?string
    {
        if ($event === null) {
            return self::LIFECYCLE_GROUPS[$lifecycle ?? ''] ?? null;
        }
        if (in_array($event, self::HANDOVER_EVENTS, true)) {
            return $reason === self::RETURNED_REASON || in_array($previous, ['returning', 'returned'], true) ? 'returned' : 'received';
        }
        foreach (self::EVENT_GROUPS as $group => $codes) {
            if (in_array($event, $codes, true)) {
                return $group;
            }
        }

        // Невідома подія не повинна ховати відправлення: воно в русі, доки не прийде фінальна.
        return 'in_transit';
    }

    /**
     * Група рядка після запису `$values`: група зберігається в рядку, щоб вибірки й бейджі не дублювали це відображення.
     * Явно переданий `status_group` (повна історія знає про повернення краще за один рядок) лишається як є.
     *
     * @param array<string, mixed> $values
     */
    public static function afterUpdate(object $row, array $values): ?string
    {
        if (array_key_exists('status_group', $values)) {
            return $values['status_group'];
        }
        $merged = $values + (array)$row;
        $event = $merged['event_code'] ?? null;
        $reason = $merged['event_reason'] ?? null;

        return self::group(
            $event === null ? null : (int)$event,
            $merged['status'] ?? null,
            $reason === null ? null : (int)$reason,
            $row->status_group ?? null,
        );
    }
}
