<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Storage\Mutex;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/**
 * Розрахунок ціни для того, що відвідувач має в кошику саме зараз. Усі вхідні дані читаються на сервері:
 * спосіб доставки, пункт видачі й кошик — з бази даних і сесії, післяплата — з прапорця обраного
 * способу оплати. Нічого з того, що тут оцінюється, не береться з браузера.
 */
final class CartQuotes
{
    private const string SESSION_SLOT = 'sviat__ukrposhta__quotes';
    private const string BUDGET_STATE = 'quote_budget';
    private const int RETRY_UNREACHABLE_AFTER = 60;
    private const int SEARCH_LIMIT = 60;
    private const int SCHEDULE_LIMIT = 20;

    /** Скільки секунд запит чекає, поки інший оновлює лічильник; запис займає мілісекунди. */
    private const int BUDGET_LOCK_WAIT = 2;

    public function __construct(
        private readonly QuoteService $service,
        private readonly Settings $settings,
        private readonly EntityFactory $entityFactory,
        private readonly CodPayments $codPayments,
        private readonly Module $module,
        private readonly Money $money,
        private readonly StateStorage $state,
        private readonly UahTotal $uahTotal,
        private readonly Mutex $mutex,
        private readonly ClientAddress $client,
    ) {
    }

    /**
     * @param bool $forOrder оформлення замовлення: один виклик на замовлення, уже саме по собі обмеження — без лімітів відвідувача
     * @param bool $forAdmin менеджер рахує ціну замовлення: він авторизований, тож без лімітів вітрини; ціну запитують,
     *     навіть коли вітрина її приховує, а сесія менеджера не є кошиком відвідувача, тож кеш не читається й не пишеться
     * @return DeliveryQuote|null null, коли цей модуль не рахує ціну для цього способу, пункту чи кошика
     */
    public function quote(object $delivery, int $officeId, int $paymentId, object $cart, bool $forOrder = false, bool $forAdmin = false): ?DeliveryQuote
    {
        if (!$this->isOwnMethod($delivery) || empty($cart->purchases)) {
            return null;
        }
        $office = $officeId > 0 ? $this->entityFactory->get(OfficeEntity::class)->get($officeId) : null;
        if (!$office || trim((string)$office->postcode) === '') {
            return null;
        }

        $config = ShippingConfig::fromSettings($this->settings->get(...));
        if ($config->senderPostcode === '') {
            return new DeliveryQuote(null, null, 'none', 'no_sender');
        }

        $cod = $this->codPayments->isCod($paymentId);
        if ($forAdmin) {
            return $this->adminQuote($config, $office, $cart, $cod);
        }
        $bag = $this->bag();
        QuoteSession::choose($bag, (int)$delivery->id, $officeId, $cod);
        $key = QuoteKey::for((int)$delivery->id, $officeId, $cod, $cart);
        $now = time();
        $cached = QuoteSession::get($bag, $key, $now, $forOrder ? null : self::RETRY_UNREACHABLE_AFTER);
        if ($cached !== null) {
            $this->store($bag);

            return $cached;
        }

        if (!empty($delivery->hide_front_delivery_price) || FreeDelivery::applies($delivery, (float)($cart->total_price ?? 0))) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'none', null);
        }
        if (!$forOrder && !QuoteThrottle::allow($bag, $now)) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'fallback', 'busy');
        }
        $metrics = $this->uahTotal->metrics($cart);
        $totalUah = $metrics->totalUah;
        $uah = $this->entityFactory->get(CurrenciesEntity::class)->findOne(['code' => 'UAH']);
        if (!$uah || $totalUah === null) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'fallback', 'no_uah');
        }
        if (!$forOrder && !$this->takeBudget($now)) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'fallback', 'busy');
        }

        $quote = $this->ask($config, $office, $metrics, $totalUah, $cod, $uah);
        // Зберігається й тоді, коли Укрпошта недосяжна: оформлення бере те, що показав кошик, а не ціну хвилиною пізніше.
        QuoteSession::put($bag, $key, $quote, $now);
        $this->store($bag);

        return $quote;
    }

    /** Безкоштовність вирішує OrderPriceRule: менеджеру потрібен тариф і для замовлення з безкоштовною доставкою. */
    private function adminQuote(ShippingConfig $config, object $office, object $cart, bool $cod): DeliveryQuote
    {
        $metrics = $this->uahTotal->metrics($cart);
        $uah = $this->entityFactory->get(CurrenciesEntity::class)->findOne(['code' => 'UAH']);
        if (!$uah || $metrics->totalUah === null) {
            return new DeliveryQuote(null, null, 'fallback', 'no_uah');
        }

        return $this->ask($config, $office, $metrics, $metrics->totalUah, $cod, $uah);
    }

    private function ask(ShippingConfig $config, object $office, CartMetrics $metrics, float $totalUah, bool $cod, object $uah): DeliveryQuote
    {
        return $this->inMainCurrency($this->service->quote(new QuoteRequest(
            $config,
            trim((string)$office->postcode),
            Parcel::fromMetrics($metrics, $config),
            $totalUah,
            $cod,
        )), $uah);
    }

    /** Свіжий розрахунок для останнього вибору відвідувача в цьому способі й поточного кошика; ніколи не викликає Укрпошту. */
    public function cachedForChoice(object $delivery, object $cart): ?DeliveryQuote
    {
        $bag = $this->bag();
        $chosen = QuoteSession::chosen($bag, (int)$delivery->id);
        if ($chosen === null || !$this->isOwnMethod($delivery)) {
            return null;
        }

        return QuoteSession::get($bag, QuoteKey::for((int)$delivery->id, $chosen['office'], $chosen['cod'], $cart), time());
    }

    /** Пункт, який відвідувач обрав для цього способу в цій сесії; 0 — ще не обирав. */
    public function chosenOffice(int $deliveryId): int
    {
        return (int)(QuoteSession::chosen($this->bag(), $deliveryId)['office'] ?? 0);
    }

    /** Пошук міст і пунктів публічний: сесія може зробити щонайбільше SEARCH_LIMIT запитів за вікно. */
    public function allowSearch(): bool
    {
        return $this->allow('search', self::SEARCH_LIMIT);
    }

    /** Розклад пункту — окремий запит до класифікатора, тож перебір пунктів не повинен ним ганяти Укрпошту. */
    public function allowScheduleLookup(): bool
    {
        return $this->allow('schedule', self::SCHEDULE_LIMIT);
    }

    private function allow(string $slot, int $limit): bool
    {
        $bag = $this->bag();
        $allowed = QuoteThrottle::allow($bag, time(), $slot, $limit);
        $this->store($bag);

        return $allowed;
    }

    public function isOwnMethod(object $delivery): bool
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);

        return $moduleId > 0 && (int)($delivery->module_id ?? 0) === $moduleId;
    }

    /** Укрпошта рахує в гривнях; ціни доставки в OkayCMS — в основній валюті магазину. */
    private function inMainCurrency(DeliveryQuote $quote, object $uah): DeliveryQuote
    {
        if ($quote->price === null) {
            return $quote;
        }
        // Точність 2 явно: за замовчуванням Money округлює до `cents` валюти UAH, а це може бути 0.
        $convert = fn(float $amount): float => round((float)$this->money->convert($amount, (int)$uah->id, false, true, 2), 2);

        return new DeliveryQuote($convert($quote->price), $quote->codFee === null ? null : $convert($quote->codFee), $quote->source, $quote->problem);
    }

    private function takeBudget(int $now): bool
    {
        // Читання й запис лічильника — під блокуванням: паралельні запити інакше проскакують ліміт.
        if (!$this->mutex->acquire(self::BUDGET_STATE, self::BUDGET_LOCK_WAIT)) {
            return false;
        }
        try {
            [$allowed, $state] = QuoteBudget::take($this->state->get(self::BUDGET_STATE), $now, QuoteBudget::LIMIT, QuoteBudget::visitor($this->client->address()), QuoteBudget::VISITOR_LIMIT);
            $this->state->set(self::BUDGET_STATE, $state);
        } finally {
            $this->mutex->release(self::BUDGET_STATE);
        }

        return $allowed;
    }

    private function bag(): array
    {
        return is_array($_SESSION[self::SESSION_SLOT] ?? null) ? $_SESSION[self::SESSION_SLOT] : [];
    }

    private function store(array $bag): void
    {
        $_SESSION[self::SESSION_SLOT] = $bag;
    }
}
