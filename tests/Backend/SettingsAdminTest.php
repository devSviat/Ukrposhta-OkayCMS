<?php

namespace Modules\Sviat\Ukrposhta\Backend;

require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';

use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryMutex;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Design;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Okay\Modules\Sviat\Ukrposhta\Backend\Controllers\UkrposhtaSettingsAdmin;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\ShippingSettings;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Ключі, параметри відправлення й запит оновлення довідників пишуть у справжні класи налаштувань поверх
 * сховищ у пам'яті; решта залежностей створена без конструкторів і впала б на першому ж зверненні.
 */
class SettingsAdminTest extends TestCase
{
    private ?int $status = null;
    /** @var array<string, mixed> */
    private array $writes = [];
    private InMemoryState $state;

    protected function setUp(): void
    {
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
    }

    /** @var array<string, mixed> */
    private array $assigned = [];

    private function call(array $post): void
    {
        [$controller, $arguments] = $this->controller($post);
        $controller->fetch(...$arguments);
    }

    /** Лише обробка POST, без рендеру сторінки: решта залежностей рендеру тут порожні. */
    private function handle(array $post): void
    {
        [$controller, $arguments] = $this->controller($post);
        $byType = [];
        foreach ($arguments as $argument) {
            $byType[$argument::class] = $argument;
        }
        $handle = new \ReflectionMethod($controller, 'handle');
        $handle->invoke($controller, ...array_map(
            static fn(\ReflectionParameter $parameter): object => $byType[(string)$parameter->getType()],
            $handle->getParameters(),
        ));
    }

    /** @return array{0: UkrposhtaSettingsAdmin, 1: list<object>} */
    private function controller(array $post): array
    {
        $_POST = $post;
        $request = new class extends Request {
            public function __construct()
            {
            }

            public function method($method = null)
            {
                return $method === null ? 'POST' : strtolower((string)$method) === 'post';
            }
        };
        $status = &$this->status;
        $response = new class ($status) extends Response {
            public function __construct(private ?int &$status)
            {
            }

            public function setStatusCode($statusCode): Response
            {
                $this->status = $statusCode;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                return $this;
            }
        };

        $controller = new \ReflectionClass(UkrposhtaSettingsAdmin::class)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);
        $assigned = &$this->assigned;
        $design = new class ($assigned) extends Design {
            public function __construct(private array &$assigned)
            {
            }

            public function assign($var, $value, $dynamicJs = false)
            {
                $this->assigned[$var] = $value;
            }
        };
        $parent->getProperty('design')->setValue($controller, $design);

        $writes = &$this->writes;
        $settings = new class ($writes) extends Settings {
            public function __construct(private array &$writes)
            {
            }

            public function get($param)
            {
                return $this->writes[$param] ?? null;
            }

            public function set($param, $value)
            {
                $this->writes[$param] = $value;
            }
        };
        $this->state = new InMemoryState();
        $blank = static fn(string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $credentials = new FixedCredentials(new Credentials('', '', '', false));
        $real = [
            KeySettings::class => new KeySettings($settings, new KeyChecker(
                $blank(ClassifierClient::class),
                $blank(EcomClient::class),
                $blank(TrackingClient::class),
                $credentials,
                $this->state,
            ), new AuthFlags($this->state)),
            ShippingSettings::class => new ShippingSettings($settings),
            DictionaryRunner::class => new DictionaryRunner(
                $blank(RegionSweep::class),
                $this->createStub(DictionaryStore::class),
                $this->state,
                new InMemoryMutex(),
                $credentials,
            ),
        ];

        $arguments = [];
        foreach (new \ReflectionMethod($controller, 'fetch')->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            $class = new \ReflectionClass($type->getName());
            $arguments[] = $real[$type->getName()] ?? ($class->isInterface()
                ? $this->createStub($type->getName())
                : $class->newInstanceWithoutConstructor());
        }

        return [$controller, $arguments];
    }

    private function assertNothingSaved(): void
    {
        self::assertSame([], $this->writes, 'settings unchanged');
        self::assertSame([], $this->state->values, 'module state unchanged');
    }

    public function testPostWithoutSessionTokenChangesNothing(): void
    {
        $this->call(['ukr_action' => 'keys', 'ecom_bearer' => 'attacker-bearer', 'clear_counterparty_token' => '1']);

        self::assertSame(403, $this->status);
        $this->assertNothingSaved();
    }

    public function testWrongSessionTokenChangesNothing(): void
    {
        $this->call(['ukr_action' => 'dictionaries', 'session_id' => str_repeat('a', 64)]);

        self::assertSame(403, $this->status);
        $this->assertNothingSaved();
    }

    public function testWrongSessionTokenLeavesShippingAlone(): void
    {
        $this->call([
            'ukr_action' => 'shipping', 'session_id' => str_repeat('b', 64),
            'shipment_type' => 'EXPRESS', 'default_weight' => '1', 'box_length' => '40', 'box_width' => '30', 'box_height' => '20',
            'discount' => '0', 'payer' => 'sender', 'cod_payer' => 'recipient', 'on_fail' => 'RETURN', 'description' => 'x', 'sms' => '1',
        ]);

        self::assertSame(403, $this->status);
        $this->assertNothingSaved();
    }

    public function testDictionaryRunWithoutBearerIsRefusedVisibly(): void
    {
        $this->handle(['ukr_action' => 'dictionaries']);

        self::assertSame([], $this->state->values, 'no queue entry');
        self::assertTrue($this->assigned['up_dict_refused'] ?? false);
    }

    public function testKeyCheckWithNoKeysAnswersInsteadOfStayingSilent(): void
    {
        $this->handle(['ukr_action' => 'keys', 'check' => '1', 'ecom_bearer' => '', 'counterparty_token' => '', 'tracking_bearer' => '']);

        self::assertSame(['sviat__ukrposhta__keys_nothing_to_check'], $this->assigned['up_keys_errors'] ?? null);
        self::assertArrayNotHasKey('up_keys_checked', $this->assigned);
        self::assertArrayNotHasKey(\Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyChecker::STATE, $this->state->values, 'no fake "checked at" result');
    }
}
