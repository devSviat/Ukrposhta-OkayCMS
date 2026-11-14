<?php

namespace Modules\Sviat\Ukrposhta\Cart;

use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\FitProblem;
use Okay\Modules\Sviat\Ukrposhta\Cart\OfficeFit;
use PHPUnit\Framework\TestCase;

class OfficeFitTest extends TestCase
{
    private static function office(string $kind): object
    {
        return (object)['kind' => $kind];
    }

    public function testLockerWithCodIsDisabled(): void
    {
        $cart = new CartMetrics(1.0, false, 100.0);

        self::assertSame([FitProblem::CodNotAllowed], OfficeFit::problems($cart, true, self::office('locker')));
        self::assertSame([FitProblem::CodNotAllowed], OfficeFit::problems($cart, true, self::office('pudo')));
        self::assertSame([], OfficeFit::problems($cart, false, self::office('locker')), 'prepaid is fine');
        self::assertSame([], OfficeFit::problems($cart, true, self::office('office')));
    }

    public function testOver30KgIsTooHeavy(): void
    {
        self::assertSame([FitProblem::TooHeavy], OfficeFit::problems(new CartMetrics(30.5, false, 1.0), false, self::office('office')));
        self::assertSame([], OfficeFit::problems(new CartMetrics(30.0, false, 1.0), false, self::office('office')), 'the limit itself fits');
    }

    public function testUnknownWeightNeverHides(): void
    {
        self::assertSame([], OfficeFit::problems(new CartMetrics(0.0, true, 1.0), false, self::office('office')));
    }

    public function testBothProblemsAreReported(): void
    {
        self::assertSame(
            [FitProblem::TooHeavy, FitProblem::CodNotAllowed],
            OfficeFit::problems(new CartMetrics(40.0, false, 1.0), true, self::office('locker')),
        );
    }
}
