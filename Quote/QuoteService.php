<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;

/**
 * Ціна доставки від відділення до відділення (W2W) з калькулятора Укрпошти. Метод не потребує
 * токена контрагента, а збій ніколи не виходить назовні: оформлення замовлення має пройти й без ціни.
 */
final class QuoteService
{
    public function __construct(
        private readonly EcomClient $ecom,
        private readonly AuthFlags $auth,
    ) {
    }

    public function quote(QuoteRequest $request): DeliveryQuote
    {
        if ($request->config->senderPostcode === '') {
            return new DeliveryQuote(null, null, 'none', 'no_sender');
        }

        try {
            $answer = $this->ecom->call('POST', 'domestic/delivery-price', $this->body($request), false);
        } catch (UkrposhtaApiException $e) {
            // Відмова авторизації лишає кошик без ціни: налаштування мають про це сказати.
            $this->auth->record(AuthFlags::QUOTE, $e);
            $reachable = !in_array($e->kind, [ApiErrorKind::Unreachable, ApiErrorKind::ServerError], true);

            return new DeliveryQuote(null, null, 'fallback', $reachable ? 'rejected' : 'unreachable');
        }

        if (!is_numeric($answer['deliveryPrice'] ?? null)) {
            return new DeliveryQuote(null, null, 'fallback', 'rejected');
        }
        $this->auth->clear(AuthFlags::QUOTE);
        $fee = $answer['postPayDeliveryPrice'] ?? null;

        return new DeliveryQuote(round((float)$answer['deliveryPrice'], 2), is_numeric($fee) ? round((float)$fee, 2) : null, 'api', null);
    }

    /** @return array<string, mixed> */
    private function body(QuoteRequest $request): array
    {
        $config = $request->config;
        $total = round($request->totalUah, 2);
        $body = [
            'addressFrom' => ['postcode' => $config->senderPostcode],
            'addressTo' => ['postcode' => $request->recipientPostcode],
            'type' => $config->type,
            'deliveryType' => 'W2W',
            'weight' => $request->parcel->weightGrams,
            'length' => $request->parcel->lengthCm,
            'width' => $request->parcel->widthCm,
            'height' => $request->parcel->heightCm,
        ];
        if ($config->declareCartTotal) {
            $body['declaredPrice'] = $total;
        }
        if ($request->cod) {
            $body['postPay'] = $total;
        }
        if ($config->discountPercent > 0) {
            // Договірну знижку калькулятор сам не знає: її передають у запиті.
            $body['discounts'] = [['description' => 'contract', 'rate' => $config->discountPercent]];
        }

        return $body;
    }
}
