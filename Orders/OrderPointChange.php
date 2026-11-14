<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelection;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\CartQuotes;
use Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryQuote;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentRows;

/** Менеджер змінює пункт видачі замовлення в адмінці. */
final class OrderPointChange
{
    /**
     * Помилки кошика (мовні ключі вітрини) як ключі адмінки. Завелика вага — рішення менеджера, тож лише
     * попередження; післяплату в поштоматі Укрпошта не видасть, тож її менеджер не обходить.
     */
    private const array ERRORS = [
        'sviat__ukrposhta__err_dictionary' => 'op_err_dictionary',
        'sviat__ukrposhta__err_city' => 'op_err_city',
        'sviat__ukrposhta__err_office' => 'op_err_office',
        'sviat__ukrposhta__err_office_city' => 'op_err_office_city',
        'sviat__ukrposhta__err_cod_locker' => 'op_err_cod_locker',
    ];
    private const string TOO_HEAVY = 'sviat__ukrposhta__err_too_heavy';

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly GroupsStore $groups,
        private readonly OrderDeliveryStore $snapshots,
        private readonly OrderParcel $parcel,
        private readonly CartQuotes $quotes,
        private readonly CodPayments $codPayments,
        private readonly OrderDeliveryLines $lines,
        private readonly ShipmentRows $shipments,
    ) {
    }

    /** @param array{city_id?: string, office_id?: string} $input */
    public function save(int $orderId, array $input): OrderPointResult
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        $delivery = $order ? $this->lines->ownDelivery($order) : null;
        if (!$order || !$delivery) {
            return OrderPointResult::failed('order_point_foreign');
        }
        $cityId = (int)($input['city_id'] ?? 0);
        $officeId = (int)($input['office_id'] ?? 0);
        $city = $cityId > 0 ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $office = $officeId > 0 ? $this->entityFactory->get(OfficeEntity::class)->get($officeId) : null;
        $cod = $this->codPayments->isCod((int)($order->payment_method_id ?? 0));
        $chosen = self::select(
            $delivery,
            (int)$this->module->getModuleIdByNamespace(__CLASS__),
            $city ?: null,
            $office ?: null,
            $this->groups->all(),
            $this->parcel->metrics($order),
            $cod,
            $this->shipments->forOrder($orderId),
        );
        if ($chosen['selection'] === null) {
            return OrderPointResult::failed((string)$chosen['error']);
        }
        [$price, $quote] = $this->price($order, $delivery, $officeId);
        $this->snapshots->save($orderId, $chosen['selection'], self::snapshotPricing($quote, $cod));
        $auto = self::autoPrice($price);
        if ($auto !== null) {
            $this->writePrice($orderId, $auto);
        }

        return new OrderPointResult(true, null, $chosen['warning'], $price);
    }

    /** Записує тариф до поточного пункту замовлення; лише коли правило його пропонує, ніколи — надіслану суму. */
    public function applyPrice(int $orderId): OrderPointResult
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        $delivery = $order ? $this->lines->ownDelivery($order) : null;
        $snapshot = $order ? $this->snapshots->forOrder($orderId) : null;
        if (!$order || !$delivery || !$snapshot) {
            return OrderPointResult::failed('order_point_foreign');
        }
        [$price] = $this->price($order, $delivery, (int)$snapshot->office_id);
        if ($price['action'] !== 'offer') {
            return new OrderPointResult(false, 'order_point_price_not_offered', null, $price);
        }
        $this->writePrice($orderId, (float)$price['price']);

        return new OrderPointResult(true, null, null, ['action' => 'applied', 'price' => $price['price']]);
    }

    /**
     * Перевіряє обраний пункт так само, як кошик, з оплатою замовлення.
     *
     * @param list<object> $groupRows рядки GroupEntity
     * @return array{selection: ?CartSelection, error: ?string, warning: ?string}
     */
    public static function select(object $delivery, int $moduleId, ?object $city, ?object $office, array $groupRows, CartMetrics $metrics, bool $cod, ?object $shipment): array
    {
        $fail = static fn(string $error): array => ['selection' => null, 'error' => $error, 'warning' => null];
        if (self::locks($shipment)) {
            return $fail(PendingShipment::is($shipment) ? 'op_err_locked_check' : 'op_err_locked');
        }
        if ($moduleId === 0 || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return $fail('order_point_foreign');
        }
        // Редактор показує лише види увімкнених груп, як і кошик; інший id прийшов не з нього.
        if ($office !== null && !CartSelectionValidator::shownIn($office, $groupRows)) {
            $office = null;
        }
        $checked = CartSelectionValidator::check($delivery, $moduleId, $city, $office, $cod, $metrics);
        if ($checked instanceof CartSelection) {
            return $checked->applies() ? ['selection' => $checked, 'error' => null, 'warning' => null] : $fail('order_point_foreign');
        }
        if ($checked === self::TOO_HEAVY) {
            return ['selection' => new CartSelection($delivery, $city, $office), 'error' => null, 'warning' => 'op_warn_too_heavy'];
        }

        return $fail(self::ERRORS[$checked] ?? 'op_err_office');
    }

    /**
     * Будь-який рядок відправлення блокує пункт: створене чи прив'язане відправлення, резерв запиту, що йде,
     * і невідомий результат — Укрпошта могла створити відправлення на старий пункт, тож його знімає лише пошук.
     */
    public static function locks(?object $shipment): bool
    {
        return $shipment !== null;
    }

    /** Сума, яку зміна пункту записує в замовлення сама; решту менеджер лише бачить або застосовує кнопкою. */
    public static function autoPrice(array $decision): ?float
    {
        return ($decision['action'] ?? null) === 'auto' && isset($decision['price']) ? (float)$decision['price'] : null;
    }

    /**
     * Комісія післяплати нового пункту; інакше до листів потрапила б комісія старого.
     *
     * @return array{cod: int, cod_fee: ?float}
     */
    public static function snapshotPricing(?DeliveryQuote $quote, bool $cod): array
    {
        return ['cod' => $cod ? 1 : 0, 'cod_fee' => $quote?->codFee];
    }

    /** @return array{0: array{action: string, price: ?float}, 1: ?DeliveryQuote} */
    private function price(object $order, object $delivery, int $officeId): array
    {
        $quote = $this->quotes->quote($delivery, $officeId, (int)($order->payment_method_id ?? 0), $this->parcel->cart($order), forAdmin: true);

        return [OrderPriceRule::decide($order, $delivery, $quote?->price, $quote === null || $quote->price === null), $quote];
    }

    private function writePrice(int $orderId, float $price): void
    {
        $orders = $this->entityFactory->get(OrdersEntity::class);
        $orders->update($orderId, ['delivery_price' => $price]);
        $orders->updateTotalPrice($orderId);
        $this->snapshots->setPrice($orderId, $price, 'admin');
    }
}
