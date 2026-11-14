<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpTransport;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;

trait SendsRequests
{
    /**
     * Відповідь 2xx або типізований виняток. Токен контрагента й bearer-и маскуються до створення винятку:
     * виняток транспорту не ланцюжиться, лише його замаскований текст.
     *
     * @param list<string> $secrets
     */
    private function deliver(HttpTransport $http, HttpRequest $request, array $secrets): HttpResponse
    {
        $where = UkrposhtaApiException::mask("{$request->method} {$request->url}", $secrets);

        try {
            $response = $http->send($request);
        } catch (TransportException $e) {
            throw UkrposhtaApiException::of(ApiErrorKind::Unreachable, $where . ': ' . UkrposhtaApiException::mask($e->getMessage(), $secrets));
        }

        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }

        $apiMessage = $response->json()['message'] ?? null;
        $apiMessage = is_string($apiMessage) && $apiMessage !== '' ? UkrposhtaApiException::mask($apiMessage, $secrets) : null;
        $kind = match (true) {
            $response->status === 401 => ApiErrorKind::BadToken,
            $response->status === 403 => ApiErrorKind::BadBearer,
            $response->status === 404 => ApiErrorKind::NotFound,
            $response->status >= 500 => ApiErrorKind::ServerError,
            default => ApiErrorKind::Rejected,
        };

        throw UkrposhtaApiException::of($kind, "{$where}: HTTP {$response->status}" . ($apiMessage === null ? '' : " {$apiMessage}"), $apiMessage);
    }
}
