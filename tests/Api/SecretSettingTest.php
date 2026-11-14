<?php

namespace Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Api\SecretSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecretSettingTest extends TestCase
{
    /** @return iterable<string, array{?string, mixed, mixed, string}> */
    public static function cases(): iterable
    {
        yield 'empty field keeps the stored token' => ['stored-aaaa', '', null, 'stored-aaaa'];
        yield 'missing field keeps the stored token' => ['stored-aaaa', null, null, 'stored-aaaa'];
        yield 'whitespace keeps the stored token' => ['stored-aaaa', '   ', null, 'stored-aaaa'];
        yield 'new value replaces it' => ['stored-aaaa', ' new-bbbb ', null, 'new-bbbb'];
        yield 'clear wipes it' => ['stored-aaaa', '', '1', ''];
        yield 'clear wins over a typed value' => ['stored-aaaa', 'new-bbbb', '1', ''];
        yield 'nothing stored yet' => [null, '', null, ''];
        yield 'array is not a token' => ['stored-aaaa', ['x'], null, 'stored-aaaa'];
    }

    #[DataProvider('cases')]
    public function testNext(?string $stored, mixed $posted, mixed $clear, string $expected): void
    {
        $this->assertSame($expected, SecretSetting::next($stored, $posted, $clear));
    }
}
