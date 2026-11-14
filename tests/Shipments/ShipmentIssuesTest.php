<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentIssues;
use PHPUnit\Framework\TestCase;

class ShipmentIssuesTest extends TestCase
{
    public function testRevokedTokenAdvice(): void
    {
        foreach ([[ApiErrorKind::BadToken, 'sviat__ukrposhta__ship_api_bad_token'], [ApiErrorKind::BadBearer, 'sviat__ukrposhta__ship_api_bad_bearer']] as [$kind, $text]) {
            $issue = ShipmentIssues::explain(UkrposhtaApiException::of($kind, 'POST shipments?token=***: HTTP 401'));

            self::assertSame($text, $issue['text']);
            self::assertSame('sviat__ukrposhta__ship_advice_key', $issue['advice']);
            self::assertNull($issue['field']);
        }
    }

    public function testNotFound(): void
    {
        self::assertSame('sviat__ukrposhta__ship_api_not_found', ShipmentIssues::explain(UkrposhtaApiException::of(ApiErrorKind::NotFound, 'x'))['text']);
    }

    public function testServerErrorAdvisesRetryLater(): void
    {
        $issue = ShipmentIssues::explain(UkrposhtaApiException::of(ApiErrorKind::ServerError, 'x', 'Internal error'));

        self::assertSame('sviat__ukrposhta__ship_api_server', $issue['text']);
        self::assertSame('Internal error', $issue['details']);
    }

    public function testKnownMessagesPointAtFields(): void
    {
        $cases = [
            'invalid onFailReceiveType' => ['on_fail', 'sviat__ukrposhta__ship_err_on_fail'],
            'Recipient street and houseNumber should be filled if delivery type - W2D' => [null, 'sviat__ukrposhta__ship_api_w2d'],
            'Invalid phoneNumber' => ['phone', 'sviat__ukrposhta__ship_err_phone'],
            'postPay must be less or equal declaredPrice' => ['cod_amount', 'sviat__ukrposhta__ship_err_cod_above_declared'],
        ];
        foreach ($cases as $message => [$field, $text]) {
            $issue = ShipmentIssues::explain(UkrposhtaApiException::of(ApiErrorKind::Rejected, "POST shipments: HTTP 400 {$message}", $message));

            self::assertSame($field, $issue['field'], $message);
            self::assertSame($text, $issue['text'], $message);
            self::assertSame($message, $issue['details'], $message);
        }
    }

    public function testUnknownRejectionKeepsUkrposhtaText(): void
    {
        $issue = ShipmentIssues::explain(UkrposhtaApiException::of(ApiErrorKind::Rejected, 'x', 'Something new'));

        self::assertSame('sviat__ukrposhta__ship_api_rejected', $issue['text']);
        self::assertSame('Something new', $issue['details']);
    }

    public function testOwnRefusalIsItsKey(): void
    {
        self::assertSame(
            ['field' => null, 'text' => 'sviat__ukrposhta__ship_err_pending', 'advice' => null, 'details' => null],
            ShipmentIssues::explain('sviat__ukrposhta__ship_err_pending'),
        );
    }

    /** Друга можливість — PROCESS_AS_REFUSAL, а не «не повертати»: менеджер має впізнати пункт налаштувань. */
    public function testOnFailTextNamesTheRefusalChoice(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend/lang/';
        foreach (['ua' => 'обробити як відмову', 'en' => 'processed as a refusal', 'ru' => 'обработать как отказ'] as $file => $phrase) {
            $lang = [];
            include $root . $file . '.php';

            self::assertStringContainsString($phrase, $lang['sviat__ukrposhta__ship_err_on_fail'], $file);
        }
    }
}
