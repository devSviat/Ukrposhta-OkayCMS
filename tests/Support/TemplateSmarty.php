<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Core\Design;
use Okay\Core\SmartyPlugins\Func;
use Okay\Core\SmartyPlugins\Modifier;

/**
 * Smarty з тими тегами, які реєструє рушій, але з заглушками замість колбеків. Власна копія
 * tests/Design/TemplateTagInventory форку: у стоку OkayCMS 4.5.3 цього файлу немає, а шаблони
 * модуля перевіряються і на Smarty 4 стоку, і на Smarty 5 форку.
 */
final class TemplateSmarty
{
    /** @param string[] $templateDirs */
    public static function create(array $templateDirs, string $compileDir): object
    {
        $root = dirname(__DIR__, 5) . '/';
        require_once $root . 'Okay/Core/config/constants.php';

        $smarty5 = class_exists('Smarty\\Smarty');
        $class = $smarty5 ? 'Smarty\\Smarty' : 'Smarty';
        $smarty = new $class();
        $smarty->setTemplateDir(array_values(array_filter($templateDirs, 'is_dir')));
        $smarty->setCompileDir($compileDir);
        // Без цього застарілий compiled мовчки зафарбував би тест у зелене.
        $smarty->setForceCompile(true);

        $design = new \ReflectionClass(Design::class);
        $phpFunctions = $design->getDefaultProperties()['allowedPhpFunctions'] ?? null;
        if (!is_array($phpFunctions)) {
            throw new \RuntimeException('Design::$allowedPhpFunctions not found');
        }

        if (!$smarty5) {
            // У Smarty 4 нативну функцію пускає політика безпеки, а не реєстрація, як і в Design стоку.
            $smarty->enableSecurity();
            $smarty->security_policy->secure_dir = [$root . 'design', $root . 'backend/design', $root . 'Okay/Modules'];
            $smarty->security_policy->php_modifiers = $phpFunctions;
            $smarty->security_policy->php_functions = $phpFunctions;
            // Design стоку глушить ці попередження через error_reporting, а PHPUnit їх однаково показує.
            $smarty->muteUndefinedOrNullWarnings();
        }

        // STATIC_CLASSES є лише у форку.
        foreach ($design->hasConstant('STATIC_CLASSES') ? $design->getConstant('STATIC_CLASSES') : [] as $staticClass) {
            $className = ltrim($staticClass, '\\');
            if (!isset($smarty->registered_classes[$staticClass]) && class_exists($className)) {
                $smarty->registerClass($staticClass, $className);
            }
        }

        // Порядок як у Design::registerSmartyPlugins(): спершу плагіни, потім нативні функції.
        foreach (self::pluginTags($root) as [$type, $tag]) {
            self::register($smarty, $smarty5, $type, $tag, $type === 'modifier'
                ? static fn(...$arguments) => ''
                : static fn($params, $template = null) => '');
        }
        foreach (array_filter($phpFunctions, 'function_exists') as $function) {
            self::register($smarty, $smarty5, 'modifier', $function, $function);
        }

        return $smarty;
    }

    /** @return list<array{0: string, 1: string}> тип і тег кожного плагіна ядра й модулів */
    private static function pluginTags(string $root): array
    {
        $classes = [];
        foreach (glob($root . 'Okay/Core/SmartyPlugins/Plugins/*.php') as $file) {
            $classes[] = 'Okay\\Core\\SmartyPlugins\\Plugins\\' . basename($file, '.php');
        }
        foreach (glob($root . 'Okay/Modules/*/*/Init/SmartyPlugins.php') as $file) {
            $definitions = include $file;
            if (is_array($definitions)) {
                $classes = array_merge($classes, array_column($definitions, 'class'));
            }
        }

        $tags = [];
        foreach (array_unique(array_filter($classes, 'class_exists')) as $class) {
            $reflector = new \ReflectionClass($class);
            $type = match (true) {
                $reflector->isSubclassOf(Func::class) => 'function',
                $reflector->isSubclassOf(Modifier::class) => 'modifier',
                default => null,
            };
            if ($type !== null) {
                $declared = $reflector->getDefaultProperties()['tag'] ?? null;
                $tags[] = [$type, !empty($declared) ? $declared : strtolower($reflector->getShortName())];
            }
        }

        return $tags;
    }

    private static function register(object $smarty, bool $smarty5, string $type, string $tag, callable $callback): void
    {
        $registered = $smarty5
            ? $smarty->getRegisteredPlugin($type, $tag) !== null
            : isset($smarty->registered_plugins[$type][$tag]);
        if (!$registered) {
            $smarty->registerPlugin($type, $tag, $callback);
        }
    }
}
