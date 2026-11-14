<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use Design\TemplateTagInventory;
use Okay\Core\BackendTranslations;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
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
        self::assertStringContainsString('up-ship__badge--arrived', $html);
        self::assertStringNotContainsString(self::XSS, $html);
        self::assertSame('', trim(self::render('orders_status.tpl', ['order' => (object)['id' => 12]])));
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

    public function testFilterOptionsAndActionsAreTranslated(): void
    {
        $filter = self::render('orders_status_filter.tpl', ['up_status_groups' => ShipmentStatus::FILTER_GROUPS, 'up_status_group' => 'none']);
        $actions = self::render('orders_action_option.tpl', []);
        $hint = self::render('orders_search_hint.tpl', ['up_status_groups' => ShipmentStatus::FILTER_GROUPS]);

        self::assertSame(count(ShipmentStatus::FILTER_GROUPS) + 1, substr_count($filter, '<option'));
        self::assertStringContainsString('value="sviat_up_create_shipments"', $actions);
        self::assertStringContainsString('value="sviat_up_print_labels"', $actions);
        self::assertStringContainsString('name="up_shipment" value="none"', self::render('orders_search.tpl', ['up_status_group' => 'none']));
        foreach ([$filter, $actions, $hint] as $html) {
            self::assertStringNotContainsString('sviat__ukrposhta__', $html, 'every translation key exists');
            self::assertNotSame('', trim(strip_tags($html)));
        }
    }

    private static function render(string $template, array $vars): string
    {
        $root = dirname(__DIR__, 5);
        require_once $root . '/tests/Design/TemplateTagInventory.php';
        $smarty = TemplateTagInventory::createSmarty(
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
}
