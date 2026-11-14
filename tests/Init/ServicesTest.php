<?php

namespace Modules\Sviat\Ukrposhta\Init;

use Okay\Core\OkayContainer\Reference\ServiceReference;
use Okay\Modules\Sviat\Ukrposhta\Init\Init;
use Okay\Modules\Sviat\Ukrposhta\Integration\UpOrders;
use PHPUnit\Framework\TestCase;

/** Контейнер передає аргументи за позицією: визначення, що розійшлося з конструктором, падає лише під час виконання. */
class ServicesTest extends TestCase
{
    public function testEveryDefinitionMatchesItsConstructor(): void
    {
        $services = require dirname((new \ReflectionClass(Init::class))->getFileName()) . '/services.php';

        foreach ($services as $name => $definition) {
            self::assertTrue(is_a($definition['class'], $name, true), "{$name} is not implemented by {$definition['class']}");
            $params = new \ReflectionClass($definition['class'])->getConstructor()?->getParameters() ?? [];
            $arguments = $definition['arguments'] ?? [];
            $required = count(array_filter($params, static fn(\ReflectionParameter $p): bool => !$p->isOptional()));
            self::assertGreaterThanOrEqual($required, count($arguments), $name);
            self::assertLessThanOrEqual(count($params), count($arguments), $name);
            foreach ($arguments as $i => $argument) {
                $type = $params[$i]->getType();
                if ($argument instanceof ServiceReference && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    self::assertTrue(is_a($argument->getName(), $type->getName(), true), "{$name} #{$i}: {$argument->getName()} is not {$type->getName()}");
                }
            }
        }
    }

    public function testIntegrationFacadeIsRegistered(): void
    {
        $services = require dirname((new \ReflectionClass(Init::class))->getFileName()) . '/services.php';

        self::assertArrayHasKey(UpOrders::class, $services);
    }
}
