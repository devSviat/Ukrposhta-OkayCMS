<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpTransport;

final class TrackingClient
{
    use SendsRequests;

    public const int MAX_BARCODES = 100;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly CredentialsProvider $credentials,
    ) {
    }

    /** Порожній bearer — трекінг не налаштовано, а не відмова Укрпошти. */
    public function configured(): bool
    {
        return $this->credentials->get()->hasTracking();
    }

    /**
     * @param list<string> $barcodes
     * @return list<array<string, mixed>>
     */
    public function lastStatuses(array $barcodes): array
    {
        if (count($barcodes) > self::MAX_BARCODES) {
            throw new \InvalidArgumentException('Tracking accepts at most ' . self::MAX_BARCODES . ' barcodes per request');
        }

        $credentials = $this->credentials->get();
        if (!$credentials->hasTracking()) {
            throw UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'Tracking bearer is not set');
        }

        $secrets = [$credentials->trackingBearer];
        $response = $this->deliver(
            $this->http,
            new HttpRequest(
                'POST',
                Credentials::TRACKING_BASE . '/statuses/last',
                ['Authorization' => 'Bearer ' . $credentials->trackingBearer, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                json_encode($barcodes, JSON_THROW_ON_ERROR),
                30,
            ),
            $secrets,
        );

        return $response->json() ?? throw UkrposhtaApiException::of(ApiErrorKind::Malformed, 'Tracking: answer is not JSON');
    }

    /**
     * Повна історія одного відправлення.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $barcode): array
    {
        $credentials = $this->credentials->get();
        if (!$credentials->hasTracking()) {
            throw UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'Tracking bearer is not set');
        }

        $response = $this->deliver(
            $this->http,
            new HttpRequest(
                'GET',
                Credentials::TRACKING_BASE . '/statuses?' . http_build_query(['barcode' => $barcode]),
                ['Authorization' => 'Bearer ' . $credentials->trackingBearer, 'Accept' => 'application/json'],
                null,
                30,
            ),
            [$credentials->trackingBearer],
        );

        return $response->json() ?? throw UkrposhtaApiException::of(ApiErrorKind::Malformed, 'Tracking: answer is not JSON');
    }

    /** Один GET без розбору відповіді: потрібен лише статус, щоб перевірити bearer. */
    public function probe(string $barcode): void
    {
        $credentials = $this->credentials->get();
        if (!$credentials->hasTracking()) {
            throw UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'Tracking bearer is not set');
        }

        $this->deliver(
            $this->http,
            new HttpRequest(
                'GET',
                Credentials::TRACKING_BASE . '/statuses/last?' . http_build_query(['barcode' => $barcode]),
                ['Authorization' => 'Bearer ' . $credentials->trackingBearer, 'Accept' => 'application/json'],
                null,
                10,
            ),
            [$credentials->trackingBearer],
        );
    }
}
