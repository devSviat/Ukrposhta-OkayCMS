<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpTransport;

/** Класифікатор адрес і відділень. Один хост і для sandbox, бо sandbox-адреси в документації немає. */
final class ClassifierClient
{
    use SendsRequests;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly CredentialsProvider $credentials,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @return list<array<string, string>>
     */
    public function rows(string $method, array $query): array
    {
        $credentials = $this->credentials->get();
        if ($credentials->ecomBearer === '') {
            throw UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'eCom bearer is not set');
        }

        $url = Credentials::CLASSIFIER_BASE . $method . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = $this->deliver(
            $this->http,
            new HttpRequest('GET', $url, ['Authorization' => 'Bearer ' . $credentials->ecomBearer, 'Accept' => 'application/json']),
            [$credentials->ecomBearer, $credentials->counterpartyToken],
        );

        $answer = $response->json();
        if ($answer === null) {
            throw UkrposhtaApiException::of(ApiErrorKind::Malformed, UkrposhtaApiException::mask("Classifier {$method}: answer is not JSON", [$credentials->ecomBearer]));
        }

        $entry = $answer['Entries']['Entry'] ?? null;
        if (!is_array($entry) || $entry === []) {
            return [];
        }

        return array_is_list($entry) ? $entry : [$entry];
    }
}
