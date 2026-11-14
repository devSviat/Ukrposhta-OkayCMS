<?php

namespace Modules\Sviat\Ukrposhta\Backend;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/SettingsPage.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Modules\Sviat\Ukrposhta\Support\SettingsPage;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettingsView;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyStatus;
use PHPUnit\Framework\TestCase;

class KeySettingsTest extends TestCase
{
    private const string ECOM = 'EQZ1EQZ1EQZ1EQZ1';
    private const string TRACKING = 'TQZ2TQZ2TQZ2TQZ2';
    private const string TOKEN = 'CQZ3CQZ3CQZ3CQZ3';

    /** @var array<string, mixed> */
    private array $settings = [];
    private InMemoryState $state;
    private FakeTransport $transport;
    private ?Credentials $credentials = null;

    protected function setUp(): void
    {
        $this->settings = [
            Credentials::ECOM_BEARER => self::ECOM,
            Credentials::TRACKING_BEARER => self::TRACKING,
            Credentials::COUNTERPARTY_TOKEN => self::TOKEN,
            Credentials::SANDBOX => 0,
        ];
        $this->state = new InMemoryState();
    }

    private function keySettings(): KeySettings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $name) => $this->settings[$name] ?? null);
        $settings->method('set')->willReturnCallback(function (string $name, $value): void {
            $this->settings[$name] = $value;
        });
        $this->transport = new FakeTransport();
        $provider = new FixedCredentials($this->credentials ?? new Credentials('', '', '', false));
        $checker = new KeyChecker(
            new ClassifierClient($this->transport, $provider),
            new EcomClient($this->transport, $provider),
            new TrackingClient($this->transport, $provider),
            $provider,
            $this->state,
        );

        return new KeySettings($settings, $checker, new AuthFlags($this->state));
    }

    public function testEmptyFieldKeepsStoredToken(): void
    {
        $this->keySettings()->save(['ecom_bearer' => '', 'tracking_bearer' => ' trk-new ', 'counterparty_token' => '   ']);

        self::assertSame(self::ECOM, $this->settings[Credentials::ECOM_BEARER]);
        self::assertSame('trk-new', $this->settings[Credentials::TRACKING_BEARER]);
        self::assertSame(self::TOKEN, $this->settings[Credentials::COUNTERPARTY_TOKEN]);
        self::assertSame([], $this->transport->requests(), 'saving never calls Ukrposhta');
    }

    public function testKeyWithInnerWhitespaceRejectsTheWholeForm(): void
    {
        foreach (["ecom-new\nX-Injected: 1", "ecom new", "ecom-new\t1", "ключ"] as $bad) {
            $errors = $this->keySettings()->save(['ecom_bearer' => $bad, 'tracking_bearer' => 'trk-new', 'sandbox' => '1']);

            self::assertSame(['sviat__ukrposhta__keys_error_chars'], $errors, json_encode($bad));
            self::assertSame(self::ECOM, $this->settings[Credentials::ECOM_BEARER]);
            self::assertSame(self::TRACKING, $this->settings[Credentials::TRACKING_BEARER], 'nothing from that form is saved');
            self::assertSame(0, $this->settings[Credentials::SANDBOX]);
        }
        self::assertSame([], $this->keySettings()->save(['ecom_bearer' => " ecom-new\n"]), 'surrounding whitespace is trimmed, not rejected');
    }

    public function testClearBoxErasesOnlyItsToken(): void
    {
        $this->keySettings()->save(['ecom_bearer' => '', 'clear_counterparty_token' => '1', 'sandbox' => '1']);

        self::assertSame(self::ECOM, $this->settings[Credentials::ECOM_BEARER]);
        self::assertSame('', $this->settings[Credentials::COUNTERPARTY_TOKEN]);
        self::assertSame(1, $this->settings[Credentials::SANDBOX]);
    }

    public function testChangedKeyForgetsTheOldCheck(): void
    {
        $this->state->set(KeyChecker::STATE, new KeyCheckResult(KeyStatus::Ok, KeyStatus::Ok, KeyStatus::Ok, new \DateTimeImmutable('@1700000000'))->toJson());
        $keys = $this->keySettings();

        $keys->save(['ecom_bearer' => '', 'sandbox' => '0']);
        self::assertNotNull($this->state->get(KeyChecker::STATE), 'nothing changed, the result still describes these keys');

        $keys->save(['ecom_bearer' => 'ecom-other']);
        self::assertNull($this->state->get(KeyChecker::STATE));
    }

    private function flagAll(): AuthFlags
    {
        $flags = new AuthFlags($this->state);
        $this->state->set('ecom_auth_failed', 'bad_token');
        $this->state->set('tracking_auth_failed', 'bad_bearer');
        $this->state->set('quote_auth_failed', 'bad_bearer');

        return $flags;
    }

    /** @return array<string, ?string> */
    private static function reasons(AuthFlags $flags): array
    {
        return array_combine(['ecom', 'tracking', 'quote'], array_map($flags->reason(...), ['ecom', 'tracking', 'quote']));
    }

    /** Новий ключ: стара відмова описує попередній ключ і лише вводила б в оману. */
    public function testNewKeyClearsOnlyItsAuthFlags(): void
    {
        $flags = $this->flagAll();
        $this->keySettings()->save(['ecom_bearer' => '', 'tracking_bearer' => self::TRACKING, 'sandbox' => '0']);
        self::assertSame(['ecom' => 'bad_token', 'tracking' => 'bad_bearer', 'quote' => 'bad_bearer'], self::reasons($flags), 'same values change nothing');

        $this->keySettings()->save(['tracking_bearer' => 'trk-new']);
        self::assertSame(['ecom' => 'bad_token', 'tracking' => null, 'quote' => 'bad_bearer'], self::reasons($flags));

        $this->keySettings()->save(['counterparty_token' => 'cp-new']);
        self::assertSame(['ecom' => null, 'tracking' => null, 'quote' => 'bad_bearer'], self::reasons($flags));

        $flags = $this->flagAll();
        $this->keySettings()->save(['ecom_bearer' => 'ecom-new']);
        self::assertSame(['ecom' => null, 'tracking' => 'bad_bearer', 'quote' => null], self::reasons($flags), 'the bearer serves both eCom and the calculator');

        $flags = $this->flagAll();
        $this->keySettings()->save(['clear_tracking_bearer' => '1']);
        self::assertNull($flags->reason('tracking'), 'tracking switched off has nothing to warn about');

        $flags = $this->flagAll();
        $this->keySettings()->save(['sandbox' => '1']);
        self::assertSame(['ecom' => null, 'tracking' => null, 'quote' => null], self::reasons($flags));
    }

    public function testSuccessfulCheckClearsAuthFlagsOfAcceptedKeys(): void
    {
        $this->credentials = new Credentials(self::ECOM, self::TRACKING, self::TOKEN, false);
        $flags = $this->flagAll();
        $keys = $this->keySettings();
        $this->transport->json(200, [['REGION_ID' => '1']])
            ->json(401, ['message' => 'Unauthorized'])
            ->json(200, []);

        $keys->check();

        self::assertSame(['ecom' => 'bad_token', 'tracking' => null, 'quote' => null], self::reasons($flags), 'a refused token keeps the eCom flag');

        $flags = $this->flagAll();
        $this->transport->json(200, [['REGION_ID' => '1']])
            ->json(404, ['message' => 'not found'])
            ->json(403, ['message' => 'Forbidden']);
        $keys->check();

        self::assertSame(['ecom' => null, 'tracking' => 'bad_bearer', 'quote' => null], self::reasons($flags));
    }

    public function testTokensNeverRenderedInHtml(): void
    {
        $credentials = new Credentials(self::ECOM, self::TRACKING, self::TOKEN, true);
        $result = new KeyCheckResult(KeyStatus::Ok, KeyStatus::BadToken, KeyStatus::Unreachable, new \DateTimeImmutable('@1700000000'));

        $html = SettingsPage::render(['up_keys' => KeySettingsView::vars($credentials, $result)]);

        self::assertStringContainsString('name="ecom_bearer"', $html, 'the key form was rendered');
        // Жодного уривка ключа: навіть хвіст допомагає вгадати чи впізнати ключ.
        foreach ([self::ECOM, self::TRACKING, self::TOKEN] as $secret) {
            for ($i = 0; $i + 4 <= strlen($secret); $i++) {
                self::assertStringNotContainsString(substr($secret, $i, 4), $html);
            }
        }
        self::assertSame(3, substr_count($html, 'Збережено ••••.'), 'every stored key shows the saved hint');
        self::assertDoesNotMatchRegularExpression('/<input[^>]+type="password"[^>]+value="[^"]/', $html);
    }

    public function testViewReportsStatusPerField(): void
    {
        $vars = KeySettingsView::vars(
            new Credentials('', self::TRACKING, self::TOKEN, false),
            new KeyCheckResult(KeyStatus::Unchecked, KeyStatus::BadToken, KeyStatus::Ok, new \DateTimeImmutable('@1700000000')),
            new \DateTimeZone('UTC'),
        );

        self::assertSame(['ecom_bearer', 'counterparty_token', 'tracking_bearer'], array_column($vars['fields'], 'name'));
        self::assertSame([false, true, true], array_column($vars['fields'], 'set'));
        self::assertSame(['missing', 'bad_token', 'ok'], array_column($vars['fields'], 'status'), 'a key that is not set says so instead of "not checked"');
        self::assertTrue($vars['any_set']);
        self::assertSame('14.11.2023 22:13', $vars['checked_at']);
        self::assertFalse($vars['sandbox']);
    }

    public function testCheckWithNoKeysSavedSaysSoAndCallsNobody(): void
    {
        $this->settings = [Credentials::SANDBOX => 0];
        $keys = $this->keySettings();

        self::assertNull($keys->check(), 'nothing to check');
        self::assertSame([], $this->transport->requests());
        self::assertSame([], $this->state->values, 'no stored result pretends a check happened');
    }

    public function testCheckWithAKeySavedReturnsTheResult(): void
    {
        $this->settings = [Credentials::TRACKING_BEARER => self::TRACKING];
        $this->credentials = new Credentials('', self::TRACKING, '', false);
        $keys = $this->keySettings();
        $this->transport->json(200, []);

        $result = $keys->check();

        self::assertInstanceOf(KeyCheckResult::class, $result);
        self::assertSame(KeyStatus::Ok, $result->tracking);
    }

    public function testNoKeysMeansCheckButtonIsOffWithAReason(): void
    {
        $vars = KeySettingsView::vars(new Credentials('', '', '', false), null);
        self::assertFalse($vars['any_set']);

        $html = SettingsPage::render(['up_keys' => $vars, 'up_keys_errors' => ['sviat__ukrposhta__keys_nothing_to_check']]);

        self::assertMatchesRegularExpression('/<button type="submit" name="check" value="1"[^>]*disabled[^>]*aria-describedby="up_keys_check_reason"/', $html);
        self::assertStringContainsString('id="up_keys_check_reason"', $html);
        self::assertStringContainsString('Жодного ключа не задано, тож перевіряти нічого.', $html);
    }

    public function testCheckResultIsShownForEveryKey(): void
    {
        $vars = KeySettingsView::vars(
            new Credentials('', self::TRACKING, '', false),
            new KeyCheckResult(KeyStatus::Unchecked, KeyStatus::Unchecked, KeyStatus::Unreachable, new \DateTimeImmutable('@1700000000')),
        );

        $html = SettingsPage::render(['up_keys' => $vars, 'up_keys_checked' => true]);

        self::assertMatchesRegularExpression('/id="up_keys_result".*Bearer eCom.*Не задано.*Токен контрагента.*Не задано.*Bearer трекінгу.*Укрпошта не відповіла/s', $html);
        self::assertDoesNotMatchRegularExpression('/<button type="submit" name="check"[^>]*disabled/', $html);
    }
}
