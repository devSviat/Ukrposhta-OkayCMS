<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use PHPUnit\Framework\TestCase;

/** Адмінка стоку OkayCMS 4.5.3 не має дизайн-токенів форку `--ok-*`: без запасного значення властивість зникає. */
class DesignTokenFallbackTest extends TestCase
{
    public function testEveryForkTokenHasAFallback(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $bare = [];
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['css', 'tpl', 'js'], true)) {
                continue;
            }
            preg_match_all('/var\(\s*--ok-[a-z0-9-]+\s*\)/', (string)file_get_contents($file->getPathname()), $found);
            foreach ($found[0] as $use) {
                $bare[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $use;
            }
        }

        self::assertSame([], $bare);
    }
}
