<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\Barcode;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase
{
    public function testFormats(): void
    {
        $cases = [
            '0500000000017' => '0500000000017',
            ' 0500 0000 00017 ' => '0500000000017',
            '05000-0000-0017' => '0500000000017',
            'RR123456785UA' => 'RR123456785UA',
            'rr123456785ua' => 'RR123456785UA',
            'CV 123456785 UA' => 'CV123456785UA',
            '050000000001' => null,
            '05000000000170' => null,
            'RR123456785US' => null,
            'R1123456785UA' => null,
            '0500000000017;' => null,
            '' => null,
        ];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, Barcode::parse((string)$input), (string)$input);
        }
    }
}
