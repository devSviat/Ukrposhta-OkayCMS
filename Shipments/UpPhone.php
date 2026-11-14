<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Телефон у форматі Укрпошти: 380XXXXXXXXX. */
final class UpPhone
{
    public static function normalize(string $raw): ?string
    {
        if (preg_match('/[^\d\s\-+()]/', $raw)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        $national = match (true) {
            str_starts_with($digits, '380') => substr($digits, 3),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        return preg_match('/^\d{9}$/', $national) ? '380' . $national : null;
    }
}
