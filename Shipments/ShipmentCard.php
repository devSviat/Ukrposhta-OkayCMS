<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Картка відправлення на сторінці замовлення: значення рядка, відформатовані для шаблону; слова — мовні ключі. */
final class ShipmentCard
{
    /** @return array{barcode: string, statusGroup: ?string, statusKey: ?string, eventName: ?string, eventAt: ?string, deliveryPrice: ?string, codAmount: ?string, codFee: ?string, payerKey: ?string, created: ?string, checked: ?string} */
    public static function fromRow(object $row): array
    {
        $group = is_string($row->status_group ?? null) && $row->status_group !== ''
            ? $row->status_group
            : ShipmentStatus::group(self::int($row->event_code ?? null), $row->status ?? null, self::int($row->event_reason ?? null));
        $eventName = trim((string)($row->event_name ?? ''));
        $paidByRecipient = $row->paid_by_recipient ?? null;

        return [
            'barcode' => (string)$row->barcode,
            'statusGroup' => $group,
            'statusKey' => $group !== null ? (ShipmentStatus::FILTER_GROUPS[$group] ?? null) : null,
            'eventName' => $eventName !== '' ? $eventName : null,
            'eventAt' => self::dateTime($row->event_at ?? null),
            'deliveryPrice' => self::money($row->delivery_price ?? null),
            'codAmount' => self::money($row->cod_amount ?? null),
            'codFee' => self::money($row->cod_fee ?? null),
            // Прив'язане вручну без відповіді Укрпошти: платник невідомий, і картка його не вигадує.
            'payerKey' => $paidByRecipient === null ? null : 'sviat__ukrposhta__party_' . ((bool)$paidByRecipient ? 'recipient' : 'sender'),
            'created' => self::dateTime($row->created_at ?? null),
            'checked' => self::dateTime($row->checked_at ?? null),
        ];
    }

    private static function money(mixed $value): ?string
    {
        return is_numeric($value) && (float)$value > 0 ? number_format((float)$value, 2, '.', '') : null;
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
