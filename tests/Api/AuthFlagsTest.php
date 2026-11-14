<?php

namespace Modules\Sviat\Ukrposhta\Api;

require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/SettingsPage.php';

use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Modules\Sviat\Ukrposhta\Support\SettingsPage;
use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use PHPUnit\Framework\TestCase;

/** Відкликаний ключ: створення, трекінг і ціна в кошику падають, і менеджер бачить чому. */
class AuthFlagsTest extends TestCase
{
    public function testNothingFlaggedNothingShown(): void
    {
        self::assertSame([], new AuthFlags(new InMemoryState())->items());
        self::assertStringNotContainsString('up_auth_notice', SettingsPage::render(['up_auth' => []]));
    }

    public function testEveryFailedApiIsListed(): void
    {
        $flags = new AuthFlags(new InMemoryState());
        self::assertTrue($flags->record(AuthFlags::TRACKING, UkrposhtaApiException::of(ApiErrorKind::BadBearer, 'x')));
        self::assertTrue($flags->record(AuthFlags::QUOTE, UkrposhtaApiException::of(ApiErrorKind::BadBearer, 'x')));
        self::assertFalse($flags->record(AuthFlags::ECOM, UkrposhtaApiException::of(ApiErrorKind::Rejected, 'x')), 'a refusal of the request says nothing about the key');

        self::assertSame(
            [['api' => 'tracking', 'reason' => 'bad_bearer'], ['api' => 'quote', 'reason' => 'bad_bearer']],
            $flags->items(),
        );
    }

    /** Трекінг і калькулятор не мають токена контрагента: 401 там означає їхній bearer. */
    public function testBearerOnlyApisNameTheBearer(): void
    {
        $flags = new AuthFlags(new InMemoryState());
        foreach ([AuthFlags::TRACKING, AuthFlags::QUOTE, AuthFlags::ECOM] as $api) {
            $flags->record($api, UkrposhtaApiException::of(ApiErrorKind::BadToken, 'x'));
        }

        self::assertSame(['bad_bearer', 'bad_bearer', 'bad_token'], [$flags->reason(AuthFlags::TRACKING), $flags->reason(AuthFlags::QUOTE), $flags->reason(AuthFlags::ECOM)]);
    }

    public function testMissingKeyIsNotARefusal(): void
    {
        $flags = new AuthFlags(new InMemoryState());

        self::assertFalse($flags->record(AuthFlags::ECOM, UkrposhtaApiException::of(ApiErrorKind::NotConfigured, 'x')));
        self::assertSame([], $flags->items());
    }

    public function testFlagsAreIndependent(): void
    {
        $flags = new AuthFlags(new InMemoryState());
        $flags->record(AuthFlags::ECOM, UkrposhtaApiException::of(ApiErrorKind::BadToken, 'x'));
        $flags->record(AuthFlags::TRACKING, UkrposhtaApiException::of(ApiErrorKind::BadBearer, 'x'));

        $flags->clear(AuthFlags::TRACKING);

        self::assertSame([['api' => 'ecom', 'reason' => 'bad_token']], $flags->items());
    }

    public function testGarbageInStateIsIgnored(): void
    {
        $state = new InMemoryState();
        $state->set('ecom_auth_failed', '<b>x</b>');
        $state->set('quote_auth_failed', 'something_else');

        self::assertSame([], new AuthFlags($state)->items());
    }

    public function testSettingsPageFlagsUnpricedCart(): void
    {
        $html = SettingsPage::render(['up_auth' => [['api' => 'quote', 'reason' => 'bad_bearer'], ['api' => 'tracking', 'reason' => 'bad_bearer']]]);

        self::assertStringContainsString('id="up_auth_notice"', $html);
        self::assertStringContainsString('доставку без ціни', $html);
        self::assertStringContainsString('Статуси відправлень не оновлюються', $html);
        self::assertStringNotContainsString('sviat__ukrposhta__', $html);
    }
}
