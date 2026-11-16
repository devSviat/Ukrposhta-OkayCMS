<?php

namespace Modules\Sviat\Ukrposhta\Init;

use PHPUnit\Framework\TestCase;

/**
 * Модуль працює і на стоковій OkayCMS 4.5.3, де простору імен Okay\Core\Security немає взагалі:
 * посилання на нього дає фатальну помилку лише там, тести форку її не бачать.
 */
class StockCoreTest extends TestCase
{
    public function testModuleDoesNotUseForkOnlySecurityClasses(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (\PhpToken::tokenize((string)file_get_contents($file->getPathname())) as $token) {
                if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $code .= $token->text;
                }
            }
            if (preg_match('/Okay\\\\Core\\\\Security\\\\/', $code)) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders);
    }
}
