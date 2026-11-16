<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/**
 * Картка відправлення на сторінці замовлення: значення рядка, відформатовані для шаблону; слова — мовні ключі.
 * Чого немає в рядку, того немає і в картці (null чи порожній список): шаблон таке поле не друкує.
 */
final class ShipmentCard
{
    private const array TYPE_KEYS = [
        'STANDARD' => 'sviat__ukrposhta__shipping_type_standard',
        'EXPRESS' => 'sviat__ukrposhta__shipping_type_express',
    ];
    private const array ON_FAIL_KEYS = [
        'RETURN' => 'sviat__ukrposhta__shipping_on_fail_return',
        'PROCESS_AS_REFUSAL' => 'sviat__ukrposhta__shipping_on_fail_refusal',
    ];

    /**
     * @param ?string $point пункт видачі зі знімка замовлення; показується, лише коли починається з індексу відправлення
     * @return array{barcode: string, statusGroup: ?string, statusKey: ?string, event: ?array{name: string, place: ?string, at: ?string}, eventName: ?string, recipient: ?string, phone: ?string, point: ?string, deliveryPrice: ?string, payerKey: ?string, plannedDate: ?string, declared: ?string, codAmount: ?string, codFee: ?string, more: array<string, string|bool>, history: list<array{name: string, place: ?string, at: string}>, created: ?string, checked: ?string}
     */
    public static function fromRow(object $row, ?string $point = null): array
    {
        $group = is_string($row->status_group ?? null) && $row->status_group !== ''
            ? $row->status_group
            : ShipmentStatus::group(self::int($row->event_code ?? null), $row->status ?? null, self::int($row->event_reason ?? null));
        $paidByRecipient = $row->paid_by_recipient ?? null;
        $details = ShipmentDetails::decode($row->details ?? null);
        $history = ShipmentHistory::decode($row->history ?? null);
        $event = self::event($row, $history);

        return [
            'barcode' => (string)$row->barcode,
            'statusGroup' => $group,
            'statusKey' => $group !== null ? (ShipmentStatus::FILTER_GROUPS[$group] ?? null) : null,
            'event' => $event,
            'eventName' => $event['name'] ?? null,
            'recipient' => self::text(implode(' ', array_filter([$details['last_name'] ?? '', $details['first_name'] ?? '', $details['middle_name'] ?? ''], is_string(...)))),
            'phone' => self::phone($details['phone'] ?? null),
            'point' => self::point($details['postcode'] ?? null, $point),
            'deliveryPrice' => self::money($row->delivery_price ?? null),
            // Прив'язане вручну без відповіді Укрпошти: платник невідомий, і картка його не вигадує.
            'payerKey' => $paidByRecipient === null ? null : 'sviat__ukrposhta__party_' . ((bool)$paidByRecipient ? 'recipient' : 'sender'),
            // Після вручення чи повернення планова дата вже нічого не каже.
            'plannedDate' => in_array($group, ShipmentStatus::FINAL, true) ? null : self::date($details['delivery_date'] ?? null),
            'declared' => self::money($details['declared'] ?? null),
            'codAmount' => self::money($row->cod_amount ?? null),
            'codFee' => self::money($row->cod_fee ?? null),
            'more' => self::more($details),
            'history' => array_map(static fn(array $e): array => [
                'name' => $e['name'],
                'place' => self::text($e['place']),
                'at' => (string)self::dateTime($e['at']),
            ], array_reverse($history)),
            'created' => self::dateTime($row->created_at ?? null),
            'checked' => self::dateTime($row->checked_at ?? null),
        ];
    }

    /**
     * Остання подія з рядка; місця колонки рядка не мають, воно є лише в тій самій події історії.
     *
     * @param list<array{code: int, name: string, place: string, at: string, reason: ?int}> $history
     * @return ?array{name: string, place: ?string, at: ?string}
     */
    private static function event(object $row, array $history): ?array
    {
        $name = self::text((string)($row->event_name ?? ''));
        if ($name === null) {
            return null;
        }
        $place = null;
        foreach ($history as $entry) {
            if ($entry['code'] === self::int($row->event_code ?? null) && $entry['at'] === ($row->event_at ?? null)) {
                $place = self::text($entry['place']);
            }
        }

        return ['name' => $name, 'place' => $place, 'at' => self::dateTime($row->event_at ?? null)];
    }

    /**
     * @param array<string, string|int|float|bool> $details
     * @return array<string, string|bool>
     */
    private static function more(array $details): array
    {
        $sides = array_filter([$details['length'] ?? null, $details['width'] ?? null, $details['height'] ?? null], is_int(...));

        return array_filter([
            'typeKey' => self::TYPE_KEYS[$details['type'] ?? ''] ?? null,
            'weight' => isset($details['weight_g']) ? (string)$details['weight_g'] : null,
            'sides' => count($sides) === 3 ? implode('×', $sides) : null,
            'description' => is_string($details['description'] ?? null) ? $details['description'] : null,
            'onFailKey' => self::ON_FAIL_KEYS[$details['on_fail'] ?? ''] ?? null,
            'sms' => is_bool($details['sms'] ?? null) ? $details['sms'] : null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private static function point(mixed $postcode, ?string $orderPoint): ?string
    {
        if (!is_string($postcode) || $postcode === '') {
            return null;
        }
        $orderPoint = trim((string)$orderPoint);

        return str_starts_with($orderPoint, $postcode . ' ') ? $orderPoint : $postcode;
    }

    /** Укрпошта зберігає номер як 380XXXXXXXXX; інший вигляд показується як є. */
    private static function phone(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return preg_match('/^380(\d{2})(\d{3})(\d{2})(\d{2})$/', $value, $m) ? "+380 {$m[1]} {$m[2]} {$m[3]} {$m[4]}" : $value;
    }

    private static function text(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function money(mixed $value): ?string
    {
        return is_numeric($value) && (float)$value > 0 ? number_format((float)$value, 2, '.', '') : null;
    }

    private static function date(mixed $value): ?string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date ? $date->format('d.m.Y') : null;
    }

    private static function dateTime(mixed $value): ?string
    {
        $date = is_string($value) && $value !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value) : false;

        return $date ? $date->format('d.m.Y H:i') : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
