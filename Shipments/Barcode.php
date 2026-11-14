<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Штрихкод (ШКІ) Укрпошти, як його вводять люди: пробіли й дефіси прибираються, літери стають великими. */
final class Barcode
{
    public static function parse(string $input): ?string
    {
        $barcode = strtoupper((string)preg_replace('/[\s\-]+/u', '', $input));

        return preg_match('/^(?:\d{13}|[A-Z]{2}\d{9}UA)$/', $barcode) === 1 ? $barcode : null;
    }
}
