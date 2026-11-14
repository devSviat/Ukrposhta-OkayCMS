<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentInput;
use PHPUnit\Framework\TestCase;

class ShipmentInputTest extends TestCase
{
    public function testTrimsAndCasts(): void
    {
        $input = ShipmentInput::fromPost([
            'type' => ' EXPRESS ',
            'weight' => ' 1500 ',
            'length' => '30,5',
            'width' => 'abc',
            'declared_price' => '1060.50',
            'cod' => '1',
            'description' => str_repeat('я', 300),
            'last_name' => '  Шевченко ',
            'unknown' => 'x',
            'height' => ['array'],
        ]);

        self::assertSame('EXPRESS', $input['type']);
        self::assertSame(1500.0, $input['weight']);
        self::assertSame(30.5, $input['length']);
        self::assertSame('abc', $input['width']);
        self::assertSame(1060.5, $input['declared_price']);
        self::assertTrue($input['cod']);
        self::assertFalse($input['sms']);
        self::assertSame(255, mb_strlen($input['description']));
        self::assertSame('Шевченко', $input['last_name']);
        self::assertArrayNotHasKey('unknown', $input);
        self::assertArrayNotHasKey('height', $input);
    }

    public function testUntickedSwitchMeansOff(): void
    {
        self::assertFalse(ShipmentInput::fromPost(['cod' => '0'])['cod']);
        self::assertFalse(ShipmentInput::fromPost([])['cod']);
    }
}
