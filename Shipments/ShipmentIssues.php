<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;

/**
 * Перетворює відмову на те, що показує форма відправлення: поле для позначки, що сталося (мовний ключ),
 * що робити (мовний ключ) і текст Укрпошти як є.
 */
final class ShipmentIssues
{
    private const string PREFIX = 'sviat__ukrposhta__';

    /** Конкретніші спершу: текст про післяплату й оголошену цінність містить обидві назви. */
    private const array RULES = [
        ['~onFailReceiveType~i', 'on_fail', 'ship_err_on_fail'],
        // Модуль надсилає лише W2W; вимога вулиці означає, що Укрпошта сприйняла доставку як адресну.
        ['~W2D|houseNumber|\bstreet\b~i', null, 'ship_api_w2d'],
        ['~postPay.*declaredPrice|declaredPrice.*postPay~i', 'cod_amount', 'ship_err_cod_above_declared'],
        ['~postPay~i', 'cod_amount', 'ship_api_cod'],
        ['~phone~i', 'phone', 'ship_err_phone'],
        ['~lastName|firstName|middleName~i', 'name', 'ship_err_name'],
        ['~weight~i', 'weight', 'ship_err_weight'],
        ['~length|width|height~i', 'sides', 'ship_err_sides'],
        ['~declaredPrice~i', 'declared_price', 'ship_err_declared'],
    ];

    /**
     * @param UkrposhtaApiException|string $problem відмова Укрпошти або мовний ключ власної відмови модуля
     * @return array{field: ?string, text: string, advice: ?string, details: ?string}
     */
    public static function explain(UkrposhtaApiException|string $problem): array
    {
        if (is_string($problem)) {
            return ['field' => null, 'text' => $problem, 'advice' => null, 'details' => null];
        }

        $details = $problem->apiMessage;
        $text = match ($problem->kind) {
            ApiErrorKind::BadToken => 'ship_api_bad_token',
            ApiErrorKind::BadBearer => 'ship_api_bad_bearer',
            ApiErrorKind::NotFound => 'ship_api_not_found',
            ApiErrorKind::NotConfigured => 'ship_err_not_configured',
            ApiErrorKind::Unreachable => 'ship_api_unreachable',
            ApiErrorKind::ServerError => 'ship_api_server',
            ApiErrorKind::Malformed, ApiErrorKind::Empty => 'ship_api_malformed',
            ApiErrorKind::Rejected => null,
        };
        if ($text !== null) {
            $advice = in_array($problem->kind, [ApiErrorKind::BadToken, ApiErrorKind::BadBearer], true) ? self::PREFIX . 'ship_advice_key' : null;

            return ['field' => null, 'text' => self::PREFIX . $text, 'advice' => $advice, 'details' => $details];
        }

        foreach (self::RULES as [$pattern, $field, $key]) {
            if (preg_match($pattern, (string)$details) === 1) {
                return ['field' => $field, 'text' => self::PREFIX . $key, 'advice' => null, 'details' => $details];
            }
        }

        return ['field' => null, 'text' => self::PREFIX . 'ship_api_rejected', 'advice' => null, 'details' => $details];
    }
}
