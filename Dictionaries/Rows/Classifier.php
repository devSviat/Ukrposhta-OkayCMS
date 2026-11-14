<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows;

/** Різні ендпоінти класифікатора називають те саме поле по-різному (і з опечатками: LATTITUDE). */
final class Classifier
{
    /** @param list<string> $names псевдоніми поля в порядку переваги */
    public static function field(array $entry, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $entry[$name] ?? null;
            if ((is_string($value) || is_int($value) || is_float($value)) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return null;
    }

    public static function flag(array $entry, array $names): int
    {
        return self::field($entry, $names) === '1' ? 1 : 0;
    }
}
