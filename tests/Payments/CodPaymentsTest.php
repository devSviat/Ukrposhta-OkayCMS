<?php

namespace Modules\Sviat\Ukrposhta\Payments;

use Okay\Modules\Sviat\Ukrposhta\Payments\CodPaymentInput;
use PHPUnit\Framework\TestCase;

class CodPaymentsTest extends TestCase
{
    public function testFlagSavedFromPost(): void
    {
        $on = [CodPaymentInput::MARKER => '1', CodPaymentInput::SWITCH => '1'];

        self::assertSame(1, CodPaymentInput::flag($on, 'null'));
        self::assertSame(1, CodPaymentInput::flag($on, ''));
        self::assertSame(0, CodPaymentInput::flag([CodPaymentInput::MARKER => '1'], 'null'), 'switch off');
        self::assertSame(0, CodPaymentInput::flag($on, 'LiqPay'), 'an online payment is never COD');
        self::assertNull(CodPaymentInput::flag([CodPaymentInput::SWITCH => '1'], 'null'), 'POST from elsewhere leaves the column alone');
        self::assertSame(0, CodPaymentInput::flag([CodPaymentInput::MARKER => '1', CodPaymentInput::SWITCH => ['1']], 'null'));
    }
}
