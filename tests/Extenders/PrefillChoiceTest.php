<?php

namespace Modules\Sviat\Ukrposhta\Extenders;

use Okay\Modules\Sviat\Ukrposhta\Extenders\FrontExtender;
use PHPUnit\Framework\TestCase;

/** З чим відкривається кошик: надіслана форма, інакше вибір, розрахований у цій сесії, інакше останнє замовлення. */
class PrefillChoiceTest extends TestCase
{
    private static function last(): object
    {
        return (object)['city_id' => 501, 'office_id' => 9001];
    }

    public function testPostedWins(): void
    {
        self::assertSame(['city_id' => 7, 'office_id' => 0], FrontExtender::prefillChoice(['city_id' => 7, 'office_id' => 0], ['city_id' => 8, 'office_id' => 80], self::last()));
    }

    public function testSessionBeforeLastOrder(): void
    {
        self::assertSame(['city_id' => 8, 'office_id' => 80], FrontExtender::prefillChoice(null, ['city_id' => 8, 'office_id' => 80], self::last()));
    }

    public function testLastOrderWhenNothingElse(): void
    {
        self::assertSame(['city_id' => 501, 'office_id' => 9001], FrontExtender::prefillChoice(null, [], self::last()));
        self::assertSame([], FrontExtender::prefillChoice(null, [], null));
    }
}
