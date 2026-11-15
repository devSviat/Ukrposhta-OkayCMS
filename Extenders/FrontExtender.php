<?php

namespace Okay\Modules\Sviat\Ukrposhta\Extenders;

use Okay\Core\Cart;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelection;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Ukrposhta\Cart\DictionaryReady;
use Okay\Modules\Sviat\Ukrposhta\Cart\PopularCities;
use Okay\Modules\Sviat\Ukrposhta\Cart\ThemeClasses;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Cart\WorkSchedule;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\CartQuotes;
use Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryPriceView;
use Okay\Modules\Sviat\Ukrposhta\Quote\OrderPrice;

class FrontExtender implements ExtensionInterface
{
    /** Тексти, потрібні скрипту кошика; ключі design/lang/*.php без префікса. */
    private const array SCRIPT_TEXTS = [
        'cart_no_results', 'cart_shown_of', 'cart_all_day', 'cart_weekdays', 'cart_day_off', 'cart_break', 'cart_schedule',
        'cart_too_heavy', 'cart_cod_not_allowed', 'cart_no_points', 'cart_search_failed', 'cart_no_city', 'cart_busy',
        'cart_cod_fee', 'cart_cod_fee_unknown', 'cart_price_unreachable', 'cart_price_rejected', 'cart_calculating', 'cart_price_by_manager',
        'cart_price_after_point', 'cart_none_fit', 'cart_kind_office', 'cart_kind_locker', 'cart_kind_pudo',
        'err_city', 'err_office', 'err_cod_locker',
    ];

    /** Вибір, перевірений getCartValidateError; зберігається, щойно замовлення створено (той самий запит). */
    private ?CartSelection $selection = null;

    /** Як розраховано ціну замовлення; зберігається від prepareDeliveryPriceInfo до знімка (той самий запит). */
    private ?array $pricing = null;

    public function __construct(
        private readonly Design $design,
        private readonly Settings $settings,
        private readonly Module $module,
        private readonly EntityFactory $entityFactory,
        private readonly FrontTranslations $translations,
        private readonly CartSelectionValidator $validator,
        private readonly OrderDeliveryStore $orders,
        private readonly Cart $cart,
        private readonly CartQuotes $quotes,
        private readonly CodPayments $codPayments,
        private readonly Money $money,
        private readonly UahTotal $uahTotal,
        private readonly PopularCities $popularCities,
        private readonly DictionaryReady $dictionary,
        private readonly OrderDeliveryLines $orderLines,
    ) {
    }

    /**
     * ChainExtension для DeliveriesHelper::getCartDeliveriesList: спосіб доставки з ціною, розрахованою
     * для пункту відвідувача, показує цю ціну, також після того, як тема перезавантажить кошик через AJAX.
     */
    public function applyQuotes($deliveries, $cart)
    {
        if (!is_array($deliveries) || !is_object($cart)) {
            return $deliveries;
        }
        foreach ($deliveries as $delivery) {
            if (!is_object($delivery)) {
                continue;
            }
            // Уже безкоштовна (free_from або безкоштовна доставка акції раніше в ланцюжку): лишається як є.
            $quote = empty($delivery->is_free_delivery) ? $this->quotes->cachedForChoice($delivery, $cart) : null;
            $own = $this->quotes->isOwnMethod($delivery);
            $total = (float)$cart->total_price;
            switch (DeliveryPriceView::cartState($delivery, $quote, $own, !$own || $this->dictionary->ready(), $total)) {
                case 'price':
                    $this->showPrice($delivery, (float)$quote?->price, $total);
                    break;
                case 'unpriced':
                    DeliveryPriceView::unpriced($delivery, $total, $this->translations->getTranslation('sviat__ukrposhta__cart_price_by_manager'));
                    break;
                case 'free':
                    DeliveryPriceView::free($delivery, $total, $this->translations->getTranslation('cart_free'));
                    break;
                case 'awaiting':
                    DeliveryPriceView::awaiting($delivery, $total, $this->translations->getTranslation('sviat__ukrposhta__cart_price_after_point'));
                    break;
            }
        }

        return $deliveries;
    }

    /**
     * ChainExtension для DeliveriesHelper::prepareDeliveryPriceInfo: ціна доставки замовлення береться з розрахунку
     * на сервері для пункту, який перевірив getCartValidateError у цьому ж запиті.
     */
    public function orderPrice($info, $delivery, $order)
    {
        if (!is_array($info) || !is_object($delivery) || !is_object($order) || !$this->quotes->isOwnMethod($delivery)) {
            return $info;
        }
        $office = $this->selection?->office;
        if ($office === null) {
            return $info;
        }
        $paymentId = (int)($order->payment_method_id ?? 0);
        $quote = $this->quotes->quote($delivery, (int)$office->id, $paymentId, $this->cart->get(), forOrder: true);
        $decision = OrderPrice::decide($quote, $info, $delivery, $order);
        $this->pricing = $quote === null ? null : [
            'price' => $decision['info']['delivery_price'] ?? null,
            'price_source' => $decision['source'],
            'cod' => $this->codPayments->isCod($paymentId) ? 1 : 0,
            // Зберігається й для оплати карткою: покупець може перейти на післяплату на сторінці замовлення.
            'cod_fee' => $quote->codFee,
        ];

        return $decision['info'];
    }

    /** ChainExtension для ValidateHelper::getCartValidateError: форма кошика перевіряється на сервері. */
    public function getCartValidateError($error, $order)
    {
        // Ланка контактів раніше в ланцюжку може зняти свою помилку і як '' (контракт ядра це дозволяє).
        if (($error !== null && $error !== '') || !is_object($order)) {
            return $error;
        }
        $result = $this->validator->validate($order, $_POST, $this->uahTotal->metrics($this->cart->get()));
        if (is_string($result)) {
            // Порожня помилка означала б, що перевірку пройдено, тож без перекладу лишається ключ.
            return $this->translations->getTranslation($result) ?: $result;
        }
        $this->selection = $result;

        return null;
    }

    /** QueueExtension для OrdersHelper::finalCreateOrderProcedure. */
    public function saveOrderDelivery($result, $order)
    {
        if (!is_object($order) || empty($order->id)) {
            return;
        }
        $selection = $this->selection ?? $this->validator->validate($order, $_POST, $this->uahTotal->metrics($this->cart->get()));
        if ($selection instanceof CartSelection) {
            $this->orders->save((int)$order->id, $selection, $this->pricing);
        }
    }

    /**
     * QueueExtension для OrdersHelper::getOrderPaymentMethodsList і NotifyHelper::finalEmailOrderUser/Admin:
     * сторінка замовлення й обидва листи показують збережений пункт. Для інших замовлень
     * присвоюється null, бо один запит може рендерити листи кількох замовлень з тим самим Design.
     */
    public function assignOrderDelivery($result, $order)
    {
        $this->design->assign('up_order_delivery', is_object($order) ? $this->orderLines->forOrder($order) : null);
        $this->design->assign('up_order_family', ThemeClasses::for((string)$this->settings->get('theme'))['family']);
    }

    /** QueueExtension для DeliveriesHelper::getCartDeliveriesList: дані для блоків кошика, лише для способів цього модуля. */
    public function getCartDeliveriesList($deliveries, $cart)
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);
        $own = array_filter((array)$deliveries, static fn($d): bool => is_object($d) && $moduleId > 0 && (int)($d->module_id ?? 0) === $moduleId);
        if ($own === []) {
            return;
        }

        $texts = [];
        foreach (self::SCRIPT_TEXTS as $key) {
            $texts[$key] = $this->translations->getTranslation('sviat__ukrposhta__' . $key);
        }

        $this->design->assign('up_module_id', $moduleId);
        $this->design->assign('up_unavailable', !$this->dictionary->ready());
        $this->design->assign('up_theme', ThemeClasses::for((string)$this->settings->get('theme')));
        $this->design->assign('up_popular', $this->popularCities->buttons());
        // JSON будується тут: Smarty 5 не дозволяє PHP-функції на кшталт json_encode як модифікатори.
        $this->design->assign('up_texts_json', json_encode($texts, JSON_UNESCAPED_UNICODE));
        $this->design->assign('up_prefill', $this->prefill($own, is_object($cart) ? $cart : (object)[]));
        $this->design->assign('up_cod_payments', array_map(fn($d): string => implode(',', $this->codPaymentIds($d)), $own));
    }

    /** @return list<int> способи оплати цього способу доставки, позначені як післяплата Укрпошти */
    private function codPaymentIds(object $delivery): array
    {
        $ids = is_array($delivery->payment_methods_ids ?? null) ? $delivery->payment_methods_ids : [];

        return array_values(array_filter(array_map(intval(...), $ids), $this->codPayments->isCod(...)));
    }

    /**
     * Вибір, який покупець уже зробив; показується знову, коли сторінка кошика повертається з помилкою.
     *
     * @param array<object> $deliveries способи доставки цього модуля
     * @return array<int, array{city:?array, office:?array, city_json:string, office_json:string}>
     */
    private function prefill(array $deliveries, object $cart): array
    {
        // Без надісланої форми попереднє замовлення покупця знову пропонує свій пункт.
        $posted = isset($_POST[CartSelectionValidator::CITY_FIELD]) || isset($_POST[CartSelectionValidator::OFFICE_FIELD]);
        $user = $this->design->getVar('user');
        $last = !$posted && is_object($user) ? $this->orders->lastForUser((int)($user->id ?? 0)) : null;
        $metrics = CartMetrics::fromCart($cart);
        $prefill = [];
        foreach ($deliveries as $delivery) {
            $deliveryId = (int)$delivery->id;
            [$cityId, $officeId] = CartSelectionValidator::postedIds($_POST, $deliveryId);
            $choice = self::prefillChoice($posted ? ['city_id' => $cityId, 'office_id' => $officeId] : null, $this->sessionChoice($deliveryId), $last);
            $described = $this->describe($delivery, $metrics, (int)($choice['city_id'] ?? 0), (int)($choice['office_id'] ?? 0));
            $prefill[$deliveryId] = $described + [
                'city_json' => $described['city'] ? (string)json_encode($described['city'], JSON_UNESCAPED_UNICODE) : '',
                'office_json' => $described['office'] ? (string)json_encode($described['office'], JSON_UNESCAPED_UNICODE) : '',
            ];
        }

        return $prefill;
    }

    /**
     * Надіслана форма, інакше вибір, розрахований у цій сесії, інакше останнє замовлення.
     *
     * @param ?array{city_id:int, office_id:int} $posted
     * @param array{city_id?:int, office_id?:int} $session
     * @return array{city_id?:int, office_id?:int}
     */
    public static function prefillChoice(?array $posted, array $session, ?object $last): array
    {
        if ($posted !== null) {
            return $posted;
        }
        if ($session !== []) {
            return $session;
        }

        return $last ? ['city_id' => (int)$last->city_id, 'office_id' => (int)$last->office_id] : [];
    }

    /** @return array{city_id?:int, office_id?:int} */
    private function sessionChoice(int $deliveryId): array
    {
        $officeId = $this->quotes->chosenOffice($deliveryId);
        $office = $officeId > 0 ? $this->entityFactory->get(OfficeEntity::class)->get($officeId) : null;

        return $office ? ['city_id' => (int)$office->city_id, 'office_id' => $officeId] : [];
    }

    /** @return array{city:?array, office:?array} */
    private function describe(object $delivery, CartMetrics $cart, int $cityId, int $officeId): array
    {
        $city = $cityId > 0 ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $office = $city && $officeId > 0 ? $this->entityFactory->get(OfficeEntity::class)->get($officeId) : null;
        // Пункт, який замовлення зараз відхилило б, повертається до списку, де показано його проблему.
        $office = $office ? $this->validator->restorable($delivery, $city, $office, $cart) : null;

        return [
            'city' => $city ? [
                'id' => (int)$city->id,
                'name' => (string)$city->name_ua,
                'type' => (string)($city->type_ua ?? ''),
                'district' => (string)($city->district_ua ?? ''),
                'region' => (string)($city->region_ua ?? ''),
            ] : null,
            'office' => $office ? [
                'id' => (int)$office->id,
                'postcode' => (string)$office->postcode,
                'kind' => (string)$office->kind,
                'title' => (string)$office->name_ua,
                'address' => (string)($office->address_ua ?? ''),
                // Лише збережений розклад: рендер сторінки не повинен чекати на класифікатор.
                'schedule' => isset($office->schedule_json) ? WorkSchedule::groups((string)$office->schedule_json) : null,
            ] : null,
        ];
    }

    private function showPrice(object $delivery, float $price, float $cartTotal): void
    {
        $currencies = $this->entityFactory->get(CurrenciesEntity::class);
        $currency = $currencies->get((int)($_SESSION['currency_id'] ?? 0)) ?: $currencies->getMainCurrency();
        DeliveryPriceView::apply(
            $delivery,
            $price,
            $cartTotal,
            fn(float $amount): string => (string)$this->money->convert($amount),
            (string)($currency->sign ?? ''),
            $this->translations->getTranslation('cart_paid_separate'),
            $this->translations->getTranslation('cart_free'),
        );
    }
}
