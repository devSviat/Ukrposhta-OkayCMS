<?php

namespace Okay\Modules\Sviat\Ukrposhta\Groups;

use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;

/** Групи, з якими стартує нове встановлення. */
final class DefaultGroups
{
    /** @return list<array{position:int, enabled:int, kinds:list<string>, names:array{uk:string, en:string}}> */
    public static function rows(): array
    {
        return [
            [
                'position' => 1,
                'enabled' => 1,
                'kinds' => [OfficeKind::Office->value],
                'names' => ['uk' => 'Відділення', 'en' => 'Post office'],
            ],
            [
                'position' => 2,
                'enabled' => 1,
                'kinds' => [OfficeKind::Locker->value],
                'names' => ['uk' => 'Поштомати', 'en' => 'Parcel lockers'],
            ],
            [
                'position' => 3,
                'enabled' => 1,
                'kinds' => [OfficeKind::Pudo->value],
                'names' => ['uk' => 'Пункти видачі', 'en' => 'Pickup points'],
            ],
        ];
    }

    /** Англійською для англійської мови сайту, українською для будь-якої іншої: менеджер їх перейменує. */
    public static function name(array $row, string $languageLabel): string
    {
        return $languageLabel === 'en' ? $row['names']['en'] : $row['names']['uk'];
    }
}
