<?php

namespace Okay\Modules\Sviat\Ukrposhta\KeyCheck;

enum KeyStatus: string
{
    case Ok = 'ok';
    case BadBearer = 'bad_bearer';
    case BadToken = 'bad_token';
    case Unreachable = 'unreachable';
    /** Ключ не заданий або перевірку неможливо виконати без іншого ключа. */
    case Unchecked = 'unchecked';
}
