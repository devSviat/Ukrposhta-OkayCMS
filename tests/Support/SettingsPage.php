<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Core\BackendTranslations;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettingsView;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\ShippingSettings;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentConfig;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsView;

/** Сторінка налаштувань, відрендерена справжнім Smarty з тими змінними, які призначає контролер. */
final class SettingsPage
{
    /** @param array<string, mixed> $vars перекривають типові */
    public static function render(array $vars = []): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-ukrposhta-admin-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        $lang = [];
        include $root . '/Okay/Modules/Sviat/Ukrposhta/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SESSION['id'] ??= 'test-session';
        $_SERVER['REQUEST_URI'] ??= '/backend/index.php';
        foreach ($vars + self::defaults() as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch('settings.tpl');
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'up_keys' => KeySettingsView::vars(new Credentials('', '', '', false), null),
            'up_sender' => ['values' => SenderSettings::fromSettings(static fn(): null => null), 'city' => null, 'offices' => []],
            'up_dict' => DictionaryStatusView::vars(new DictionaryStatus(), 1_700_000_000, [], true),
            'up_groups' => GroupsView::vars([], []),
            'up_popular' => [],
            'up_shipping' => ShippingSettings::form(ShipmentConfig::fromSettings(static fn(): null => null)),
            'up_cod_payments' => [],
            'up_free_for_all' => [],
            'up_auth' => [],
        ];
    }
}
