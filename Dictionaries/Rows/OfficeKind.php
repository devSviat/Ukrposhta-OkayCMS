<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows;

/** Вид пункту, як його бачить покупець: від нього залежать вкладка в кошику й доступність післяплати. */
enum OfficeKind: string
{
    case Office = 'office';
    case Locker = 'locker';
    case Pudo = 'pudo';

    /**
     * Білий список: пересувні відділення (ПВ, ПВПЗ) і всі службові типи — `null`. Пересувне відділення
     * перетворює W2W на W2D, а кошик продає лише доставку у пункт.
     *
     * @param string|null $short скорочена назва типу; береться, коли ендпоінт не дав акронім
     */
    public static function fromType(string $acronym, ?string $short): ?self
    {
        $type = trim($acronym) !== '' ? trim($acronym) : trim((string)$short);

        return match ($type) {
            'МВ', 'МВПЗ', 'СВ', 'СВПЗ', 'ВВ', 'ЦКД' => self::Office,
            'П-т' => self::Locker,
            'PUDO' => self::Pudo,
            default => null,
        };
    }
}
