<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\SecretSetting;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyStatus;

/** Блок ключів сторінки налаштувань: ключі лише записуються, перевіряються окремою кнопкою. */
final class KeySettings
{
    /** Поле форми → налаштування, у порядку показу на сторінці. */
    public const array FIELDS = [
        'ecom_bearer' => Credentials::ECOM_BEARER,
        'counterparty_token' => Credentials::COUNTERPARTY_TOKEN,
        'tracking_bearer' => Credentials::TRACKING_BEARER,
    ];

    /** Чию відмову авторизації описував попередній ключ: bearer eCom обслуговує й калькулятор ціни. */
    private const array FLAGS = [
        'ecom_bearer' => [AuthFlags::ECOM, AuthFlags::QUOTE],
        'counterparty_token' => [AuthFlags::ECOM],
        'tracking_bearer' => [AuthFlags::TRACKING],
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly KeyChecker $checker,
        private readonly AuthFlags $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @return list<string> мовні ключі помилок; якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $posted = is_string($post[$field] ?? null) ? trim($post[$field]) : '';
            // Ключ іде в заголовок Authorization і в query: пробіл чи перенесення рядка всередині зламали б запит.
            if ($posted !== '' && !preg_match('/^[\x21-\x7E]+$/', $posted)) {
                return ['sviat__ukrposhta__keys_error_chars'];
            }
        }
        $stale = [];
        foreach (self::FIELDS as $field => $setting) {
            $stored = $this->settings->get($setting);
            $next = SecretSetting::next(is_string($stored) ? $stored : null, $post[$field] ?? null, $post['clear_' . $field] ?? null);
            if ($next !== (string)$stored) {
                array_push($stale, ...self::FLAGS[$field]);
            }
            $this->settings->set($setting, $next);
        }
        if (array_key_exists('sandbox', $post)) {
            $sandbox = empty($post['sandbox']) ? 0 : 1;
            if ($sandbox !== (int)$this->settings->get(Credentials::SANDBOX)) {
                array_push($stale, AuthFlags::ECOM, AuthFlags::QUOTE, AuthFlags::TRACKING);
            }
            $this->settings->set(Credentials::SANDBOX, $sandbox);
        }
        if ($stale !== []) {
            $this->checker->forget();
            $this->auth->clear(...array_unique($stale));
        }

        return [];
    }

    /**
     * Звертається до Укрпошти; викликається лише кнопкою «Перевірити ключі».
     *
     * @return KeyCheckResult|null null, коли не збережено жодного ключа: перевіряти нічого, і результат «перевірено» вводив би в оману
     */
    public function check(): ?KeyCheckResult
    {
        if (!$this->anyStored()) {
            return null;
        }
        $result = $this->checker->check();
        $this->checker->remember($result);
        if ($result->ecom === KeyStatus::Ok) {
            $this->auth->clear(AuthFlags::QUOTE);
            // Bearer прийнято: відмова eCom через bearer застаріла, а про токен скаже лише його власна перевірка.
            if ($this->auth->reason(AuthFlags::ECOM) === KeyStatus::BadBearer->value) {
                $this->auth->clear(AuthFlags::ECOM);
            }
        }
        if ($result->token === KeyStatus::Ok) {
            $this->auth->clear(AuthFlags::ECOM);
        }
        if ($result->tracking === KeyStatus::Ok) {
            $this->auth->clear(AuthFlags::TRACKING);
        }

        return $result;
    }

    private function anyStored(): bool
    {
        foreach (self::FIELDS as $setting) {
            $stored = $this->settings->get($setting);
            if (is_string($stored) && trim($stored) !== '') {
                return true;
            }
        }

        return false;
    }

    public function lastResult(): ?KeyCheckResult
    {
        return $this->checker->lastResult();
    }
}
