<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use Modules\Sviat\Ukrposhta\Support\TemplateSmarty;
use Okay\Core\BackendTranslations;
use PHPUnit\Framework\TestCase;

/** Шаблони списку замовлень компілюються Smarty 5, екранують текст Укрпошти й не лишають ключів без перекладу. */
class OrdersListTemplatesTest extends TestCase
{
    private const string XSS = '<img src=x onerror=alert(1)>';

    public function testBadgeEscapesUkrposhtaText(): void
    {
        $html = self::render('orders_status.tpl', ['order' => (object)['id' => 11, 'up_shipment' => [
            'barcode' => '0500000000017', 'group' => 'arrived', 'statusKey' => 'sviat__ukrposhta__status_arrived', 'eventName' => self::XSS,
        ]]]);

        self::assertStringContainsString('data-copy-string="0500000000017"', $html);
        self::assertMatchesRegularExpression('~<svg class="up-list-status__logo" width="14.79" height="14" viewBox="79 87 356 337" aria-hidden="true">.*</svg>\s*<a [^>]*up-list-status__barcode~s', $html, 'monogram before the number');
        self::assertStringNotContainsString('<img', $html, 'inline, no request');
        self::assertStringContainsString('up-ship__badge--arrived', $html);
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertSame('', trim(self::render('orders_status.tpl', ['order' => (object)['id' => 12]])));
        $unsettled = self::render('orders_status.tpl', ['order' => (object)['id' => 13, 'up_shipment' => ['barcode' => null, 'group' => 'unsettled', 'statusKey' => 'sviat__ukrposhta__list_unsettled', 'eventName' => null]]]);
        self::assertStringContainsString('up-list-status__logo', $unsettled, 'the carrier is visible before the barcode exists');
    }

    public function testMassResultEscapesRowsAndError(): void
    {
        $html = self::render('mass_result.tpl', ['up_mass_result' => [
            'title' => 'створено 0 з 1',
            'rows' => [['orderId' => 7, 'kind' => 'failed', 'text' => self::XSS]],
            'untouched' => null,
            'error' => self::XSS,
        ]]);

        self::assertStringContainsString('up-mass__row--failed', $html);
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertSame(2, substr_count($html, '&lt;img src=x'));
    }

    public function testActionsAreTranslated(): void
    {
        $actions = self::render('orders_action_option.tpl', []);

        self::assertStringContainsString('value="sviat_up_create_shipments"', $actions);
        self::assertStringContainsString('value="sviat_up_print_labels"', $actions);
        self::assertStringNotContainsString('sviat__ukrposhta__', $actions, 'every translation key exists');
        self::assertNotSame('', trim(strip_tags($actions)));
    }

    private static function render(string $template, array $vars): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/../Support/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-ukrposhta-list-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        $lang = [];
        include $root . '/Okay/Modules/Sviat/Ukrposhta/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SERVER['REQUEST_URI'] ??= '/backend/index.php?controller=OrdersAdmin';
        foreach ($vars as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    /** Номер копіюється так само, як у Нової Пошти: елемент ядра .fn_clipboard, підказка «Натисніть, щоб скопіювати», жовте «Скопійовано». */
    public function testNumberCopiesLikeNovaPoshta(): void
    {
        $html = self::render('orders_status.tpl', ['order' => (object)['id' => 11, 'up_shipment' => ['barcode' => '0500000000017', 'group' => 'arrived', 'statusKey' => 'sviat__ukrposhta__status_arrived', 'eventName' => null]]]);
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend';
        $script = (string)file_get_contents($root . '/design/js/shipment.js');

        self::assertMatchesRegularExpression('~<a href="" class="up-list-status__barcode fn_clipboard fn_up_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="Натисніть, щоб скопіювати" data-hint-copied="Скопійовано" data-copy-string="0500000000017"~u', $html);
        self::assertStringContainsString("event.target.closest('.fn_up_copy')", $script);
        self::assertStringNotContainsString('fn_up_ship_copy', $script);
        self::assertStringNotContainsString('fn_up_list_copy', $script);
        self::assertStringNotContainsString('sviat__ukrposhta__list_copy', (string)file_get_contents($root . '/lang/ua.php'));
    }
}
