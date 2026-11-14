<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use PHPUnit\Framework\TestCase;

/** Адмінка залежить від відправлень, а не навпаки: інакше зміна сторінки налаштувань ламає створення відправлень. */
class LayeringTest extends TestCase
{
    /** SenderClient читає SenderSettings ще з частини 2; винесення відправника — окрема зміна. */
    private const array KNOWN = ['Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings'];

    public function testShipmentsDoNotUseBackendClasses(): void
    {
        $found = [];
        foreach (glob(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Shipments/*.php') as $file) {
            preg_match_all('/^use (Okay\\\\Modules\\\\Sviat\\\\Ukrposhta\\\\Backend\\\\[\w\\\\]+);/m', (string)file_get_contents($file), $uses);
            foreach (array_diff($uses[1], self::KNOWN) as $class) {
                $found[] = basename($file) . ': ' . $class;
            }
        }

        self::assertSame([], $found);
    }
}
