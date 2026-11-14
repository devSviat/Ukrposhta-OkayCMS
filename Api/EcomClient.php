<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpTransport;

final class EcomClient
{
    use SendsRequests;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly CredentialsProvider $credentials,
    ) {
    }

    /** Обидва ключі запитів з токеном задані; порожній — модуль ще не налаштовано, а не відмова Укрпошти. */
    public function configured(): bool
    {
        return $this->credentials->get()->hasEcom();
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<mixed> порожнє тіло 2xx дає []
     */
    public function call(string $method, string $path, ?array $body = null, bool $withToken = true): array
    {
        $credentials = $this->credentials->get();
        $this->assertConfigured($credentials, $withToken);

        $url = $credentials->ecomBase() . ltrim($path, '/') . ($withToken ? $this->tokenQuery($path, $credentials) : '');
        $headers = $this->headers($credentials);
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->deliver(
            $this->http,
            new HttpRequest($method, $url, $headers, $body === null ? null : $this->encode($body), 30),
            $this->secrets($credentials),
        );

        if (trim($response->body) === '') {
            return [];
        }

        return $response->json() ?? throw UkrposhtaApiException::of(
            ApiErrorKind::Malformed,
            UkrposhtaApiException::mask("{$method} {$url}: answer is not JSON", $this->secrets($credentials)),
        );
    }

    /**
     * PDF з бази форм (ярлики, накладні); повертає байти як є.
     *
     * @param array<string, scalar> $query
     */
    public function pdf(string $path, array $query): string
    {
        $credentials = $this->credentials->get();

        return $this->fetchPdf('GET', $credentials, $path, $query, null);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed> $body
     */
    public function pdfPost(string $path, array $query, array $body): string
    {
        $credentials = $this->credentials->get();

        return $this->fetchPdf('POST', $credentials, $path, $query, $this->encode($body));
    }

    /** @param array<string, scalar> $query */
    private function fetchPdf(string $method, Credentials $credentials, string $path, array $query, ?string $body): string
    {
        $this->assertConfigured($credentials, true);

        $url = $credentials->formsBase() . ltrim($path, '/') . '?' . http_build_query($query + ['token' => $credentials->counterpartyToken], '', '&', PHP_QUERY_RFC3986);
        $headers = $this->headers($credentials);
        $headers['Accept'] = 'application/pdf';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->deliver($this->http, new HttpRequest($method, $url, $headers, $body, 60), $this->secrets($credentials));
        if (str_starts_with($response->body, '%PDF')) {
            return $response->body;
        }

        // База форм може відповісти 200 з JSON-помилкою: без цієї перевірки менеджер скачав би її як PDF.
        $apiMessage = ($response->json() ?? [])['message'] ?? null;
        $apiMessage = is_string($apiMessage) && $apiMessage !== '' ? UkrposhtaApiException::mask($apiMessage, $this->secrets($credentials)) : null;

        throw UkrposhtaApiException::of(
            ApiErrorKind::Malformed,
            UkrposhtaApiException::mask("{$method} {$url}: answer is not a PDF", $this->secrets($credentials)) . ($apiMessage === null ? '' : " {$apiMessage}"),
            $apiMessage,
        );
    }

    /**
     * Запит, що не кодується, не пішов і не може бути створеним на боці Укрпошти: Rejected, а не Malformed
     * чи Unreachable, після яких відправлення вважається «можливо створеним».
     *
     * @param array<string, mixed> $body
     */
    private function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw UkrposhtaApiException::of(ApiErrorKind::Rejected, 'Request body cannot be encoded as JSON: ' . $e->getMessage());
        }
    }

    private function assertConfigured(Credentials $credentials, bool $withToken): void
    {
        if ($credentials->ecomBearer === '') {
            throw UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'eCom bearer is not set');
        }
        if ($withToken && $credentials->counterpartyToken === '') {
            throw UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'Counterparty token is not set');
        }
    }

    private function tokenQuery(string $path, Credentials $credentials): string
    {
        return (str_contains($path, '?') ? '&' : '?') . 'token=' . rawurlencode($credentials->counterpartyToken);
    }

    /** @return array<string, string> */
    private function headers(Credentials $credentials): array
    {
        return ['Authorization' => 'Bearer ' . $credentials->ecomBearer, 'Accept' => 'application/json'];
    }

    /** @return list<string> */
    private function secrets(Credentials $credentials): array
    {
        return [$credentials->ecomBearer, $credentials->counterpartyToken];
    }
}
