<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Shipments\UpPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpPhoneTest extends TestCase
{
    public static function phones(): iterable
    {
        yield 'international with separators' => ['+380 67 123-45-67', '380671234567'];
        yield 'national' => ['0671234567', '380671234567'];
        yield 'without leading zero' => ['671234567', '380671234567'];
        yield 'already normalized' => ['380671234567', '380671234567'];
        yield 'brackets' => ['(067) 123 45 67', '380671234567'];
        yield 'too short' => ['12345', null];
        yield 'empty' => ['', null];
        yield 'letters' => ['067abc4567', null];
        yield 'foreign number' => ['+48123456789', null];
    }

    #[DataProvider('phones')]
    public function testNormalize(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, UpPhone::normalize($raw));
    }
}
