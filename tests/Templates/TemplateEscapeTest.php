<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use Okay\Modules\Sviat\Ukrposhta\Init\Init;
use PHPUnit\Framework\TestCase;

/**
 * Шаблони адмінки й теми не мають autoescape: кожне виведене значення потребує |escape. Без нього дозволено:
 * capture (його частини екрановано там, де їх захоплено) і |convert (число, відформатоване ядром).
 */
class TemplateEscapeTest extends TestCase
{
    public function testEveryPrintedValueIsEscaped(): void
    {
        $root = dirname((string)new \ReflectionClass(Init::class)->getFileName(), 2);
        $files = [...(glob($root . '/Backend/design/html/*.tpl') ?: []), ...(glob($root . '/design/html/*.tpl') ?: [])];
        self::assertNotEmpty($files);
        $raw = [];
        foreach ($files as $file) {
            foreach (file($file) as $n => $line) {
                preg_match_all('/\{\$[^}]*\}/', $line, $m);
                foreach ($m[0] as $tag) {
                    if (str_contains($tag, '|escape') || preg_match('/^\{\$[\w.>-]+\s*=/', $tag) || str_starts_with($tag, '{$smarty.capture.') || str_contains($tag, '|convert')) {
                        continue;
                    }
                    $raw[] = basename(dirname($file, 2)) . '/' . basename($file) . ':' . ($n + 1) . ' ' . $tag;
                }
            }
        }

        self::assertSame([], $raw);
    }

    /** Теми показують блок опису оплати, щойно front_cart_payment щось виводить, навіть перенесення рядка. */
    public function testCodFeeBlockPrintsNothingOutsideTheLine(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/design/html/cart_cod_fee.tpl');
        $withoutTags = preg_replace('/\{\*.*?\*\}|\{[^}]*\}/s', '', $template);

        self::assertSame('', preg_replace('/<span\b[^>]*><\/span>/', '', (string)$withoutTags));
    }
}
