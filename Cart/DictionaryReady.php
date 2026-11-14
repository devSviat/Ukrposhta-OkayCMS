<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/** Чи може кошик зараз запропонувати пункти: довідники вже хоч раз завантажено. */
final class DictionaryReady
{
    private ?bool $ready = null;

    public function __construct(
        private readonly StateStorage $state,
        private readonly EntityFactory $entityFactory,
    ) {
    }

    /**
     * Невдалий чи перерваний запуск лишає рядки попереднього, тож лише статус останнього запуску
     * вимкнув би кошик на час збою Укрпошти; тоді вирішує, чи є в таблиці хоч одне місто.
     */
    public function ready(): bool
    {
        return $this->ready ??= self::loadedByLastRun(DictionaryStatus::load($this->state))
            || $this->entityFactory->get(CityEntity::class)->cols(['id'])->findOne([]) !== false;
    }

    public static function loadedByLastRun(DictionaryStatus $status): bool
    {
        return in_array($status->lastRun['status'] ?? null, [DictionaryStatus::OK, DictionaryStatus::PARTIAL], true);
    }
}
