<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;

/**
 * Лише те, що напевно не вміщується: сама відома вага перевищує ліміт місця, або післяплата
 * в поштоматі чи PUDO. Вгадана вага ніколи не приховує пункт.
 */
final class OfficeFit
{
    /** Ліміт eCom на одне місце, кг. */
    public const float MAX_PLACE_WEIGHT = 30.0;

    /** @return list<FitProblem> */
    public static function problems(CartMetrics $cart, bool $cod, object $office): array
    {
        $problems = [];
        if ($cart->knownWeight > self::MAX_PLACE_WEIGHT + 1e-9) {
            $problems[] = FitProblem::TooHeavy;
        }
        $kind = OfficeKind::tryFrom((string)($office->kind ?? ''));
        if ($cod && ($kind === OfficeKind::Locker || $kind === OfficeKind::Pudo)) {
            $problems[] = FitProblem::CodNotAllowed;
        }

        return $problems;
    }
}
