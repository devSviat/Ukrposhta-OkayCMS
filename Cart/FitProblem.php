<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

enum FitProblem: string
{
    case TooHeavy = 'too_heavy';
    case CodNotAllowed = 'cod_not_allowed';
}
