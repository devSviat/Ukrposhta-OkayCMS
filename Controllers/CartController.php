<?php

namespace Okay\Modules\Sviat\Ukrposhta\Controllers;

use Okay\Core\Cart;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Money;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\UserHelper;
use Okay\Modules\Sviat\Ukrposhta\Cart\CitySearch;
use Okay\Modules\Sviat\Ukrposhta\Cart\CrossSiteRequest;
use Okay\Modules\Sviat\Ukrposhta\Cart\OfficeList;
use Okay\Modules\Sviat\Ukrposhta\Cart\ScheduleCache;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Cart\WorkSchedule;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\CartQuotes;
use Okay\Modules\Sviat\Ukrposhta\Quote\DeliveryPriceView;

/**
 * Публічні endpoints кошика. Вони читають лише довідник Укрпошти і власний кошик відвідувача:
 * жодних замовлень, жодних персональних даних. До Укрпошти з них іде лише збережений індекс пункту.
 */
class CartController
{
    public function cities(Request $request, Response $response, CitySearch $search, CartQuotes $quotes)
    {
        if (!$quotes->allowSearch()) {
            $response->setStatusCode(429);
            $this->json($response, ['cities' => [], 'problem' => 'busy']);

            return;
        }
        $query = self::typed($request);
        $this->json($response, ['cities' => is_string($query) ? $search->find($query, 10) : []]);
    }

    public function offices(Request $request, Response $response, OfficeList $offices, Cart $cart, UserHelper $userHelper, UahTotal $uahTotal, CartQuotes $quotes, CodPayments $codPayments)
    {
        if (!$quotes->allowSearch()) {
            $response->setStatusCode(429);
            $this->json($response, ['groups' => [], 'group' => null, 'points' => [], 'problem' => 'busy']);

            return;
        }
        $cityId = (int)$request->get('city_id', 'integer');
        if ($cityId <= 0) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'city_id']);

            return;
        }
        $groupId = (int)$request->get('group_id', 'integer');
        $query = self::typed($request);
        $this->json($response, $offices->forCity(
            $cityId,
            $uahTotal->metrics(self::visitorCart($cart, $userHelper)),
            $codPayments->isCod((int)$request->get('payment_id', 'integer')),
            $groupId > 0 ? $groupId : null,
            is_string($query) ? $query : '',
        ));
    }

    /**
     * Ціна й розклад обраного пункту (`ajax/ukrposhta/quote?delivery_id=&office_id=&payment_id=`). Числа способу
     * доставки беруться з власного списку кошика ядра, тож кожен модуль, що його розширює (безкоштовна доставка
     * промо), формує відповідь так само, як формує сторінку.
     */
    public function quote(Request $request, Response $response, CartQuotes $quotes, Cart $cart, UserHelper $userHelper, EntityFactory $entityFactory, Money $money, DeliveriesHelper $deliveriesHelper, FrontTranslations $translations, ScheduleCache $schedules)
    {
        // Маршрут пише вибір у сесію: посилання з чужого сайту не повинне його змінювати.
        if (CrossSiteRequest::is($_SERVER)) {
            $response->setStatusCode(403);
            $this->json($response, ['error' => 'origin']);

            return;
        }
        $deliveryId = (int)$request->get('delivery_id', 'integer');
        $officeId = (int)$request->get('office_id', 'integer');
        $delivery = $deliveryId > 0 ? $entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        $cartData = self::visitorCart($cart, $userHelper);
        $paymentId = (int)$request->get('payment_id', 'integer');
        $quote = $delivery && !empty($delivery->enabled) ? $quotes->quote($delivery, $officeId, $paymentId, $cartData) : null;
        if ($quote === null) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'input']);

            return;
        }
        if ($quote->problem === 'busy') {
            $response->setStatusCode(429);
        }

        $answer = ['price' => null, 'unpriced' => false, 'priceText' => null, 'totalWithDelivery' => null, 'free' => false, 'codFee' => null, 'codFeeText' => null, 'problem' => $quote->problem, 'schedule' => $this->schedule($officeId, $entityFactory, $schedules, $quotes)];
        $payments = $entityFactory->get(PaymentsEntity::class)->mappedBy('id')->find(['enabled' => 1]);
        $item = $deliveriesHelper->getCartDeliveriesList($cartData, $payments)[$deliveryId] ?? null;
        // Недоступність Укрпошти не зберігається в сесії, тож список вище ще може містити ціну з адмінки.
        if ($item && $quote->unpriced()) {
            DeliveryPriceView::unpriced($item, (float)$cartData->total_price, $translations->getTranslation('sviat__ukrposhta__cart_price_by_manager'));
        }
        if ($item && ($quote->price !== null || !empty($item->is_free_delivery) || $quote->unpriced())) {
            $answer['unpriced'] = $quote->unpriced() && empty($item->is_free_delivery);
            $answer['price'] = (float)$item->price;
            $answer['priceText'] = (string)$item->delivery_price_text;
            $answer['totalWithDelivery'] = (float)$item->total_price_with_delivery;
            $answer['free'] = !empty($item->is_free_delivery);
            if ($quote->codFee !== null && !$answer['free']) {
                $answer['codFee'] = $quote->codFee;
                $answer['codFeeText'] = $money->convert($quote->codFee) . ' ' . $this->currencySign($entityFactory);
            }
        }
        $this->json($response, $answer);
    }

    /** Розклад зі збереженого, а запит до класифікатора — лише в межах ліміту сесії. */
    private function schedule(int $officeId, EntityFactory $entityFactory, ScheduleCache $schedules, CartQuotes $quotes): ?array
    {
        $office = $officeId > 0 ? $entityFactory->get(OfficeEntity::class)->get($officeId) : null;
        if (!$office) {
            return null;
        }
        $stored = isset($office->schedule_json) ? (string)$office->schedule_json : null;
        if (!ScheduleCache::isFresh($stored, time()) && !$quotes->allowScheduleLookup()) {
            return $stored === null ? null : WorkSchedule::groups($stored);
        }
        $rows = $schedules->for($office);

        return $rows === null ? null : WorkSchedule::groups((string)json_encode($rows));
    }

    private function currencySign(EntityFactory $entityFactory): string
    {
        $currencies = $entityFactory->get(CurrenciesEntity::class);
        $currency = $currencies->get((int)($_SESSION['currency_id'] ?? 0)) ?: $currencies->getMainCurrency();

        return (string)($currency->sign ?? '');
    }

    private function json(Response $response, array $data): void
    {
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    /**
     * Текст пошуку як введено. Request::get() типово кодує HTML, що перетворює "Кам'янець" на
     * "Кам&#039;янець" і нічого не знаходить; значення лише підставляється в запити й повертається як JSON.
     */
    private static function typed(Request $request): mixed
    {
        return $request->get('q', null, null, false);
    }

    /**
     * Кошик відвідувача так, як його бачить сторінка кошика. Ці endpoints оминають AbstractController ядра,
     * чий mergeCart(true) і завантажує кошик авторизованого покупця: Cart::init() лишає його порожнім.
     */
    private static function visitorCart(Cart $cart, UserHelper $userHelper): Cart
    {
        $userHelper->mergeCart(true);

        return $cart->get();
    }
}
