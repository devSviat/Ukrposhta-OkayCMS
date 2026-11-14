<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Entities\DeliveriesEntity;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\OfficeKind;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;

/**
 * Перевіряє на сервері те, що каже форма кошика: браузер може надіслати будь-які id. Формі
 * не довіряємо ні в чому, крім двох id, і лише для способів доставки цього модуля.
 */
final class CartSelectionValidator
{
    public const string CITY_FIELD = 'ukrposhta_city_id';
    public const string OFFICE_FIELD = 'ukrposhta_office_id';

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly GroupsStore $groups,
        private readonly CodPayments $codPayments,
        private readonly DictionaryReady $dictionary,
    ) {
    }

    /** @return CartSelection|string вибір або мовний ключ помилки, яку треба показати */
    public function validate(object $order, array $post, CartMetrics $cart): CartSelection|string
    {
        $deliveryId = (int)($order->delivery_id ?? 0);
        $delivery = $deliveryId > 0 ? $this->entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        $moduleId = $this->moduleId();
        if (!$delivery || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return CartSelection::none();
        }
        [$cityId, $officeId] = self::postedIds($post, $deliveryId);
        $city = $cityId > 0 ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $office = $officeId > 0 ? $this->entityFactory->get(OfficeEntity::class)->get($officeId) : null;

        return self::check(
            $delivery,
            $moduleId,
            $city ?: null,
            $office ? $this->shown($office) : null,
            $this->codPayments->isCod((int)($order->payment_method_id ?? 0)),
            $cart,
            $this->dictionary->ready(),
        );
    }

    /**
     * Пункт, який відновлює кошик (надіслана форма, сесія, останнє замовлення), або null, коли
     * оформлення замовлення зараз його відхилило б. Оплата ще не відома: післяплату перевіряє скрипт і сервер.
     */
    public function restorable(object $delivery, ?object $city, ?object $office, CartMetrics $cart): ?object
    {
        $office = $office ? $this->shown($office) : null;

        return $office !== null && self::check($delivery, $this->moduleId(), $city, $office, false, $cart) instanceof CartSelection ? $office : null;
    }

    public static function check(?object $delivery, int $moduleId, ?object $city, ?object $office, bool $cod, CartMetrics $cart, bool $dictionaryReady = true): CartSelection|string
    {
        if ($delivery === null || $moduleId === 0 || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return CartSelection::none();
        }
        if (!$dictionaryReady) {
            return 'sviat__ukrposhta__err_dictionary';
        }
        if ($city === null) {
            return 'sviat__ukrposhta__err_city';
        }
        if ($office === null || OfficeKind::tryFrom((string)($office->kind ?? '')) === null) {
            return 'sviat__ukrposhta__err_office';
        }
        if ((int)$office->city_id !== (int)$city->id) {
            return 'sviat__ukrposhta__err_office_city';
        }
        $problems = OfficeFit::problems($cart, $cod, $office);
        if (in_array(FitProblem::CodNotAllowed, $problems, true)) {
            return 'sviat__ukrposhta__err_cod_locker';
        }
        if (in_array(FitProblem::TooHeavy, $problems, true)) {
            return 'sviat__ukrposhta__err_too_heavy';
        }

        return new CartSelection($delivery, $city, $office);
    }

    /** @return array{0:int, 1:int} id міста й пункту, надіслані для цього способу доставки */
    public static function postedIds(array $post, int $deliveryId): array
    {
        $read = static function (string $field) use ($post, $deliveryId): int {
            $value = is_array($post[$field] ?? null) ? ($post[$field][$deliveryId] ?? null) : null;

            return is_scalar($value) ? max(0, (int)$value) : 0;
        };

        return [$read(self::CITY_FIELD), $read(self::OFFICE_FIELD)];
    }

    private function shown(object $office): ?object
    {
        return self::shownIn($office, $this->groups->all()) ? $office : null;
    }

    /**
     * Пункт виду, якого немає в жодній увімкненій групі, кошик не показує, тож і форма не може його обрати.
     *
     * @param list<object> $groupRows рядки GroupEntity
     */
    public static function shownIn(object $office, array $groupRows): bool
    {
        foreach ($groupRows as $group) {
            if (in_array((string)($office->kind ?? ''), OfficeList::kindsOf([$group], (int)$group->id), true)) {
                return true;
            }
        }

        return false;
    }

    private function moduleId(): int
    {
        return (int)$this->module->getModuleIdByNamespace(__CLASS__);
    }
}
