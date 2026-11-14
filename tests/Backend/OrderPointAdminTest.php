<?php

namespace Modules\Sviat\Ukrposhta\Backend;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Modules\Sviat\Ukrposhta\Backend\Controllers\OrderPointAdmin;
use Okay\Modules\Sviat\Ukrposhta\Cart\CitySearch;
use Okay\Modules\Sviat\Ukrposhta\Cart\OfficeList;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderParcel;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointChange;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use PHPUnit\Framework\TestCase;

/**
 * Залежності створено без конструкторів: будь-яка дія, що пройшла б охорону, впала б на першому ж
 * зверненні до них, тож зелений тест означає, що до дії справа не дійшла.
 */
class OrderPointAdminTest extends TestCase
{
    /** @var array{status: ?int, body: ?array} */
    private array $answer = ['status' => null, 'body' => null];

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

    private function call(string $method, array $post): void
    {
        $_POST = $post;
        $request = new class ($method) extends Request {
            public function __construct(private string $verb)
            {
            }

            public function method($method = null)
            {
                return $method === null ? $this->verb : strtolower((string)$method) === strtolower($this->verb);
            }
        };
        $answer = &$this->answer;
        $response = new class ($answer) extends Response {
            public function __construct(private array &$answer)
            {
            }

            public function setStatusCode($statusCode): Response
            {
                $this->answer['status'] = $statusCode;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                $this->answer['body'] = json_decode((string)$content, true);

                return $this;
            }
        };

        $controller = new \ReflectionClass(OrderPointAdmin::class)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);

        $blank = static fn(string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $controller->fetch(
            $blank(OrderPointChange::class),
            $blank(OrderParcel::class),
            $blank(CitySearch::class),
            $blank(OfficeList::class),
            $blank(CodPayments::class),
            $blank(BackendTranslations::class),
            $blank(OrderDeliveryLines::class),
        );
    }

    public function testGetIsRejected(): void
    {
        $this->call('GET', ['action' => 'save', 'order_id' => '5', 'office_id' => '9001']);

        self::assertSame(405, $this->answer['status']);
        self::assertSame(['ok' => false], $this->answer['body']);
    }

    public function testMissingSessionTokenIsRejected(): void
    {
        $this->call('POST', ['action' => 'save', 'order_id' => '5', 'city_id' => '501', 'office_id' => '9001']);

        self::assertSame(403, $this->answer['status']);
        self::assertSame(['ok' => false], $this->answer['body']);
    }

    public function testWrongSessionTokenIsRejected(): void
    {
        $this->call('POST', ['action' => 'cities', 'q' => 'Київ', 'session_id' => str_repeat('a', 64)]);

        self::assertSame(403, $this->answer['status']);
    }
}
