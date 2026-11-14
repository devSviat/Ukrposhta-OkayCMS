<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

final readonly class Credentials
{
    public const string ECOM_BEARER = 'sviat__ukrposhta__ecom_bearer';
    public const string TRACKING_BEARER = 'sviat__ukrposhta__tracking_bearer';
    public const string COUNTERPARTY_TOKEN = 'sviat__ukrposhta__counterparty_token';
    public const string SANDBOX = 'sviat__ukrposhta__sandbox';

    public const string CLASSIFIER_BASE = 'https://www.ukrposhta.ua/address-classifier-ws/';
    public const string TRACKING_BASE = 'https://www.ukrposhta.ua/status-tracking/0.0.1';

    public function __construct(
        public string $ecomBearer,
        public string $trackingBearer,
        public string $counterpartyToken,
        public bool $sandbox,
    ) {
    }

    /** @param callable(string): mixed $get */
    public static function fromSettings(callable $get): self
    {
        $text = static fn(string $key): string => is_string($value = $get($key)) ? trim($value) : '';

        return new self(
            $text(self::ECOM_BEARER),
            $text(self::TRACKING_BEARER),
            $text(self::COUNTERPARTY_TOKEN),
            !empty($get(self::SANDBOX)),
        );
    }

    /** eCom потребує і bearer, і токен контрагента. */
    public function hasEcom(): bool
    {
        return $this->ecomBearer !== '' && $this->counterpartyToken !== '';
    }

    public function hasTracking(): bool
    {
        return $this->trackingBearer !== '';
    }

    public function ecomBase(): string
    {
        return $this->sandbox ? 'https://dev.ukrposhta.ua/ecom/0.0.1/' : 'https://www.ukrposhta.ua/ecom/0.0.1/';
    }

    public function formsBase(): string
    {
        return $this->sandbox ? 'https://dev.ukrposhta.ua/forms/ecom/0.0.1/' : 'https://www.ukrposhta.ua/forms/ecom/0.0.1/';
    }
}
