<?php

namespace Modules\Sviat\Ukrposhta\Templates;

require_once __DIR__ . '/../Support/SettingsPage.php';

use Modules\Sviat\Ukrposhta\Support\SettingsPage;
use Okay\Modules\Sviat\Ukrposhta\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsView;
use PHPUnit\Framework\TestCase;

/** Сторінка налаштувань, відрендерена справжнім Smarty з даними в кожному блоці. */
class SettingsTemplateTest extends TestCase
{
    private const string XSS = '<img src=x onerror=alert(1)>';

    private static function page(): string
    {
        $status = new DictionaryStatus();
        $status->lastRun = [
            'started' => 1_700_000_000, 'finished' => 1_700_000_600, 'status' => DictionaryStatus::PARTIAL,
            'counts' => ['offices' => ['added' => 3, 'changed' => 1, 'deleted' => 0, 'unchanged' => 9]],
            'skipped' => ['Київська ' . self::XSS => 'HTTP 500'], 'unresolved' => ['Крим'], 'error' => null,
        ];
        $sender = SettingsPage::defaults()['up_sender'];
        $sender['values']['name'] = 'ТОВ ' . self::XSS;
        $sender['values']['postcode'] = '01001';
        $sender['city'] = ['id' => 5, 'name' => 'Київ', 'type' => 'місто', 'region' => 'Київ'];
        $sender['offices'] = [['postcode' => '01001', 'title' => '01001 · Київ 1']];

        return SettingsPage::render([
            'up_dict' => DictionaryStatusView::vars($status, 1_700_001_000, ['office' => 11000, 'locker' => 4, 'pudo' => 0, 'cities' => 9000], true),
            'up_sender' => $sender,
            'up_sender_errors' => ['sender_iban' => 'sviat__ukrposhta__sender_error_iban'],
            'up_groups' => GroupsView::vars([(object)['id' => 1, 'name' => 'Відділення ' . self::XSS, 'enabled' => 1, 'position' => 1, 'kinds' => '["office"]']], ['office' => 11000]),
            'up_popular' => [['id' => 5, 'name' => 'Київ', 'type' => 'місто', 'region' => 'Київ']],
            'up_cod_payments' => [(object)['id' => 3, 'name' => 'Готівкою ' . self::XSS, 'enabled' => 0]],
        ]);
    }

    public function testEveryBlockRendersAndEscapes(): void
    {
        $html = self::page();

        foreach (['up_keys_form', 'up_dict_form', 'up_sender_form', 'up_shipping_form', 'up_groups_form', 'up_popular_form'] as $form) {
            self::assertStringContainsString('id="' . $form . '"', $html);
        }
        self::assertSame(6, substr_count($html, 'name="session_id"'), 'every form carries the session token');
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('IBAN має починатися з UA', $html);
        self::assertStringContainsString('Частину областей не оновлено', $html);
        self::assertStringContainsString('<option value="01001" selected>01001 · Київ 1</option>', $html);
        self::assertStringContainsString('name="kinds[locker]"', $html);
        self::assertStringNotContainsString('sviat__ukrposhta__', $html, 'every translation key exists');
    }

    public function testRefreshButtonIsOffWithoutBearer(): void
    {
        $html = SettingsPage::render([
            'up_dict' => DictionaryStatusView::vars(new DictionaryStatus(), 1_700_000_000, [], false),
            'up_dict_refused' => true,
        ]);

        self::assertMatchesRegularExpression('/<form method="post" id="up_dict_form">.*?<button type="submit"[^>]*disabled[^>]*aria-describedby="up_dict_needs_bearer"/s', $html);
        self::assertStringContainsString('Кнопка запрацює, коли буде збережено bearer eCom.', $html);
        self::assertStringContainsString('Оновлення не поставлено в чергу', $html);
        self::assertStringNotContainsString('Оновлюється…', $html);

        $ready = SettingsPage::render();
        self::assertDoesNotMatchRegularExpression('/<form method="post" id="up_dict_form">.*?<button type="submit"[^>]*disabled/s', $ready);
        self::assertStringNotContainsString('up_dict_needs_bearer', $ready);
    }

    public function testSenderTypeUsesAdminRadios(): void
    {
        $html = self::page();

        self::assertMatchesRegularExpression('/<input id="up_sender_type_company" class="hidden_check" type="radio" name="sender_type" value="COMPANY" checked>\s*<label for="up_sender_type_company" class="okay_type_radio">/', $html);
        self::assertMatchesRegularExpression('/<fieldset[^>]*data-up-sender="PRIVATE_ENTREPRENEUR" hidden disabled>/', $html, 'inactive type is disabled so the browser does not validate it');
    }
}
