<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use Okay\Modules\Sviat\Ukrposhta\Extenders\FrontExtender;
use PHPUnit\Framework\TestCase;

class FrontLanguageFilesTest extends TestCase
{
    private static function dir(): string
    {
        return dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/design';
    }

    /** @return array<string, string> */
    private static function load(string $label): array
    {
        $lang = [];
        include self::dir() . "/lang/{$label}.php";

        return $lang;
    }

    /** @return list<string> */
    private static function scriptTexts(): array
    {
        return (new \ReflectionClassConstant(FrontExtender::class, 'SCRIPT_TEXTS'))->getValue();
    }

    public function testAllLanguagesHaveTheSameKeys(): void
    {
        $ua = array_keys(self::load('ua'));
        sort($ua);
        foreach (['en', 'ru'] as $label) {
            $keys = array_keys(self::load($label));
            sort($keys);
            self::assertSame($ua, $keys, $label);
        }
    }

    public function testTemplateAndScriptTextsExist(): void
    {
        $ua = self::load('ua');
        $templates = glob(self::dir() . '/html/*.tpl') ?: [];
        preg_match_all('/\$lang->(sviat__ukrposhta__\w+)/', implode("\n", array_map('file_get_contents', $templates)), $m);
        $used = $m[1];
        foreach (self::scriptTexts() as $key) {
            $used[] = 'sviat__ukrposhta__' . $key;
        }

        self::assertSame([], array_values(array_diff(array_unique($used), array_keys($ua))));
    }

    public function testEveryValidationErrorHasAText(): void
    {
        $ua = self::load('ua');
        $code = (string)file_get_contents(dirname(self::dir()) . '/Cart/CartSelectionValidator.php');
        preg_match_all("/return '(sviat__ukrposhta__err_\\w+)';/", $code, $m);

        self::assertCount(6, $m[1]);
        foreach ($m[1] as $key) {
            self::assertArrayHasKey($key, $ua);
        }
    }

    public function testScriptReadsOnlyTextsItIsGiven(): void
    {
        $script = (string)file_get_contents(self::dir() . '/js/ukrposhta_cart.js');
        preg_match_all('/texts\.((?:cart|err)_\w+)/', $script, $m);
        $read = $m[1];
        // Причини з OfficeList і види пунктів читаються за ключем, складеним у скрипті.
        foreach (['too_heavy', 'cod_not_allowed'] as $reason) {
            $read[] = 'cart_' . $reason;
        }
        foreach (['office', 'locker', 'pudo'] as $kind) {
            $read[] = 'cart_kind_' . $kind;
        }

        self::assertSame([], array_values(array_diff(array_unique($read), self::scriptTexts())));
    }

    public function testScriptNeverWritesMarkup(): void
    {
        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|\.html\(/', (string)file_get_contents(self::dir() . '/js/ukrposhta_cart.js'));
    }
}
