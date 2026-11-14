<?php

namespace Modules\Sviat\Ukrposhta\Api;

use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use PHPUnit\Framework\TestCase;

class CredentialsTest extends TestCase
{
    public function testFromSettingsAndPresence(): void
    {
        $settings = [
            'sviat__ukrposhta__ecom_bearer' => ' ecom-aaaa ',
            'sviat__ukrposhta__tracking_bearer' => '',
            'sviat__ukrposhta__counterparty_token' => 'cp-bbbb',
            'sviat__ukrposhta__sandbox' => '1',
        ];
        $credentials = Credentials::fromSettings(static fn(string $key): mixed => $settings[$key] ?? null);

        $this->assertSame('ecom-aaaa', $credentials->ecomBearer);
        $this->assertSame('cp-bbbb', $credentials->counterpartyToken);
        $this->assertTrue($credentials->sandbox);
        $this->assertTrue($credentials->hasEcom());
        $this->assertFalse($credentials->hasTracking());
        $this->assertSame('https://dev.ukrposhta.ua/ecom/0.0.1/', $credentials->ecomBase());
    }

    public function testEmptySettingsMeanNothingConfiguredAndProd(): void
    {
        $credentials = Credentials::fromSettings(static fn(string $key): mixed => null);

        $this->assertFalse($credentials->hasEcom());
        $this->assertFalse($credentials->hasTracking());
        $this->assertFalse($credentials->sandbox);
        $this->assertSame('https://www.ukrposhta.ua/ecom/0.0.1/', $credentials->ecomBase());
        $this->assertSame('https://www.ukrposhta.ua/forms/ecom/0.0.1/', $credentials->formsBase());
    }

    public function testEcomNeedsBothBearerAndCounterpartyToken(): void
    {
        $credentials = new Credentials('ecom-aaaa', '', '', false);

        $this->assertFalse($credentials->hasEcom());
    }
}
