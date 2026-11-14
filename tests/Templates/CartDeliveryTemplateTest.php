<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use Design\TemplateTagInventory;
use Okay\Modules\Sviat\Ukrposhta\Cart\ThemeClasses;
use PHPUnit\Framework\TestCase;

/** Блок кошика, відрендерений справжнім Smarty: форма надсилає саме ті поля, які читає валідатор. */
class CartDeliveryTemplateTest extends TestCase
{
    private static function render(array $vars): string
    {
        require_once dirname(__DIR__, 4) . '/Design/TemplateTagInventory.php';
        $dir = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/design/html';
        $smarty = TemplateTagInventory::createSmarty([$dir], sys_get_temp_dir() . '/okaycms-ukrposhta-tpl-' . getmypid());
        $lang = [];
        include dirname($dir) . '/lang/ua.php';
        $smarty->assign('lang', (object)$lang);
        foreach ($vars as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch('cart_delivery.tpl');
    }

    private static function vars(array $override = []): array
    {
        return $override + [
            'delivery' => (object)['id' => 6, 'module_id' => 81, 'hide_front_delivery_price' => 0],
            'up_module_id' => 81,
            'up_unavailable' => false,
            'up_theme' => ThemeClasses::for('okay_shop'),
            'up_popular' => [['id' => 501, 'label' => 'Вінниця', 'type' => 'місто']],
            'up_texts_json' => '{"cart_busy":"\"<b>\""}',
            'up_cod_payments' => [6 => '3'],
            'up_prefill' => [6 => [
                'city' => ['id' => 501, 'name' => 'Вінниця <script>', 'type' => 'місто', 'district' => '', 'region' => 'Вінницька'],
                'office' => null,
                'city_json' => '{"id":501,"name":"Вінниця <script>"}',
                'office_json' => '',
            ]],
        ];
    }

    public function testFormCarriesCityAndOfficeIdsForThisDelivery(): void
    {
        $html = self::render(self::vars());

        self::assertStringContainsString('class="up-cart__city-input', $html);
        self::assertMatchesRegularExpression('/<input type="hidden" class="up-cart__city-id" name="ukrposhta_city_id\[6\]" value="501">/', $html);
        self::assertMatchesRegularExpression('/<input type="hidden" class="up-cart__office-id" name="ukrposhta_office_id\[6\]" value="">/', $html);
        self::assertStringContainsString('data-cod-payments="3"', $html);
    }

    public function testDirectoryDataIsEscaped(): void
    {
        $html = self::render(self::vars());

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Вінниця &lt;script&gt;', $html);
        self::assertStringNotContainsString('"<b>"', $html);
    }

    public function testOtherDeliveriesGetNothing(): void
    {
        self::assertSame('', trim(self::render(self::vars(['delivery' => (object)['id' => 6, 'module_id' => 12, 'hide_front_delivery_price' => 0]]))));
    }

    public function testUnavailableDictionaryShowsOnlyTheMessage(): void
    {
        $html = self::render(self::vars(['up_unavailable' => true]));

        self::assertStringContainsString('up-cart-unavailable', $html);
        self::assertStringNotContainsString('ukrposhta_office_id', $html);
    }
}
