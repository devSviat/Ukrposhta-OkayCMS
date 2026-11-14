<?php

namespace Okay\Modules\Sviat\Ukrposhta\KeyCheck;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\CredentialsProvider;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/** Перевіряє bearer eCom, токен контрагента й bearer трекінгу, перш ніж менеджер на них покладеться. */
final class KeyChecker
{
    public const string STATE = 'key_check';

    private const string TRACKING_PROBE_BARCODE = '0000000000000';

    private readonly \Closure $now;

    public function __construct(
        private readonly ClassifierClient $classifier,
        private readonly EcomClient $ecom,
        private readonly TrackingClient $tracking,
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    public function check(): KeyCheckResult
    {
        $credentials = $this->credentials->get();
        $ecom = $credentials->ecomBearer === '' ? KeyStatus::Unchecked : $this->probeEcomBearer();
        $token = $credentials->hasEcom() ? $this->probeToken() : KeyStatus::Unchecked;
        $tracking = $credentials->hasTracking() ? $this->probeTracking() : KeyStatus::Unchecked;

        return new KeyCheckResult($ecom, $token, $tracking, new \DateTimeImmutable('@' . ($this->now)()));
    }

    public function remember(KeyCheckResult $result): void
    {
        $this->state->set(self::STATE, $result->toJson());
    }

    /** Результат описує ключі, які щойно замінили; показувати його далі означало б вводити в оману. */
    public function forget(): void
    {
        $this->state->set(self::STATE, null);
    }

    public function lastResult(): ?KeyCheckResult
    {
        $json = $this->state->get(self::STATE);

        return $json === null ? null : KeyCheckResult::fromJson($json);
    }

    // Проба обрана без живого ключа: підтвердити на sandbox.
    private function probeEcomBearer(): KeyStatus
    {
        try {
            $this->classifier->rows('get_regions_by_region_ua', ['region_name' => 'Київ']);

            return KeyStatus::Ok;
        } catch (UkrposhtaApiException $e) {
            return match ($e->kind) {
                ApiErrorKind::BadBearer => KeyStatus::BadBearer,
                ApiErrorKind::NotConfigured => KeyStatus::Unchecked,
                default => KeyStatus::Unreachable,
            };
        }
    }

    // Проба обрана без живого ключа: підтвердити на sandbox. Очікується, що неіснуючий клієнт дає 404, а хибний токен 401.
    private function probeToken(): KeyStatus
    {
        try {
            $this->ecom->call('GET', 'clients/external-id/sviat-keycheck');

            return KeyStatus::Ok;
        } catch (UkrposhtaApiException $e) {
            return match ($e->kind) {
                ApiErrorKind::NotFound => KeyStatus::Ok,
                ApiErrorKind::BadToken => KeyStatus::BadToken,
                ApiErrorKind::BadBearer, ApiErrorKind::NotConfigured => KeyStatus::Unchecked,
                default => KeyStatus::Unreachable,
            };
        }
    }

    // Проба обрана без живого ключа: підтвердити на sandbox. GET /statuses/last у документації немає, тож 404, 405
    // чи інша відмова можуть означати відсутній маршрут, а не прийнятий ключ: такий результат лишається неперевіреним.
    private function probeTracking(): KeyStatus
    {
        try {
            $this->tracking->probe(self::TRACKING_PROBE_BARCODE);

            return KeyStatus::Ok;
        } catch (UkrposhtaApiException $e) {
            return match ($e->kind) {
                ApiErrorKind::BadBearer, ApiErrorKind::BadToken => KeyStatus::BadBearer,
                ApiErrorKind::Unreachable, ApiErrorKind::ServerError => KeyStatus::Unreachable,
                default => KeyStatus::Unchecked,
            };
        }
    }
}
