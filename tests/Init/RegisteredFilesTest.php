<?php

namespace Modules\Sviat\Ukrposhta\Init;

use Okay\Modules\Sviat\Ukrposhta\Init\Init;
use PHPUnit\Framework\TestCase;

/**
 * Init::init() виконується на кожен запит без try/catch: блок, чийого файла немає, кидає виняток у
 * DesignBlocks::registerBlock(), і кожна сторінка вітрини й адмінки відповідає 500.
 */
class RegisteredFilesTest extends TestCase
{
    private static function root(): string
    {
        return dirname((string)new \ReflectionClass(Init::class)->getFileName(), 2);
    }

    /** @return list<string> шляхи файлів, які Init.php реєструє; відносно каталогу модуля */
    public static function registeredFiles(string $source): array
    {
        $files = [];
        preg_match_all("/->addFrontBlock\\(\\s*'[^']+'\\s*,\\s*'([^']+)'/", $source, $front);
        foreach ($front[1] as $file) {
            $files[] = 'design/html/' . basename($file);
        }
        preg_match_all("/->addBackendBlock\\(\\s*'[^']+'\\s*,\\s*'([^']+)'/", $source, $backend);
        foreach ($backend[1] as $file) {
            $files[] = 'Backend/design/html/' . basename($file);
        }
        preg_match_all("/->registerBackendController\\(\\s*'([^']+)'/", $source, $controllers);
        foreach ($controllers[1] as $controller) {
            $files[] = 'Backend/Controllers/' . $controller . '.php';
        }

        return $files;
    }

    public function testEveryRegisteredFileExists(): void
    {
        $files = self::registeredFiles((string)file_get_contents(self::root() . '/Init/Init.php'));

        self::assertContains('Backend/design/html/email_delivery.tpl', $files, 'the parser sees the calls');
        $missing = array_values(array_filter($files, static fn(string $file): bool => !is_file(self::root() . '/' . $file)));
        self::assertSame([], $missing);
    }

    /** Головний контролер модуля — посилання зі списку модулів; без файла воно веде на 500. */
    public function testMainBackendControllerIsRegisteredAndExists(): void
    {
        $source = (string)file_get_contents(self::root() . '/Init/Init.php');
        self::assertSame(1, preg_match("/->setBackendMainController\\(\\s*'([^']+)'/", $source, $main));

        self::assertContains('Backend/Controllers/' . $main[1] . '.php', self::registeredFiles($source));
        self::assertFileExists(self::root() . '/Backend/Controllers/' . $main[1] . '.php');
    }

    public function testFrontRoutesPointToExistingMethods(): void
    {
        $routes = require self::root() . '/Init/routes.php';
        self::assertNotEmpty($routes);
        foreach ($routes as $name => $route) {
            self::assertTrue(method_exists($route['params']['controller'], $route['params']['method']), $name);
        }
    }

    public function testModuleTemplatesFromModuleJsonExist(): void
    {
        $json = json_decode((string)file_get_contents(self::root() . '/Init/module.json'), true);
        foreach (['front' => 'design/html/', 'backend' => 'Backend/design/html/'] as $side => $dir) {
            foreach ($json['modifications'][$side] ?? [] as $file) {
                foreach ($file['changes'] as $change) {
                    foreach (['append', 'appendBefore', 'appendAfter', 'prepend', 'replace'] as $action) {
                        $value = $change[$action] ?? '';
                        if (str_ends_with($value, '.tpl')) {
                            self::assertFileExists(self::root() . '/' . $dir . $value);
                        }
                    }
                }
            }
        }
    }
}
