<?php

namespace Modules\Sviat\Ukrposhta\Backend;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryShipmentRows.php';
require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryShipmentRows;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Backend\Controllers\ShipmentAdmin;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\RecipientParty;
use Okay\Modules\Sviat\Ukrposhta\Shipments\SenderClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDrafts;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentService;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentTracking;
use PHPUnit\Framework\TestCase;

/**
 * Укрпошта тут — FakeTransport без жодної підготовленої відповіді: будь-який запит, якого тест не чекає,
 * кидає виняток. Залежності, яких дія не повинна торкатися, створено без конструкторів.
 */
class ShipmentAdminTest extends TestCase
{
    private const string TOKEN = 'cp-token-1234';

    /** @var array{status: ?int, body: mixed, type: ?string, headers: list<string>} */
    private array $answer = ['status' => null, 'body' => null, 'type' => null, 'headers' => []];
    private FakeTransport $transport;
    private InMemoryShipmentRows $rows;

    protected function setUp(): void
    {
        $_POST = [];
        $_SESSION = [];
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryShipmentRows();
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

            public function addHeader($headerContent, $replace = true, $responseCode = null): Response
            {
                $this->answer['headers'][] = $headerContent;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                $this->answer['type'] = $type;
                $this->answer['body'] = $type === RESPONSE_JSON ? json_decode((string)$content, true) : $content;

                return $this;
            }
        };

        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('get')->willReturnCallback(static fn($id) => (int)$id === 7 ? (object)['id' => 7, 'delivery_id' => 6] : false);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($orders);

        $controller = new \ReflectionClass(ShipmentAdmin::class)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);
        $parent->getProperty('entityFactory')->setValue($controller, $entityFactory);

        $ecom = new EcomClient($this->transport, new FixedCredentials(new Credentials('ecom-aaaa', 'trk', self::TOKEN, false)));
        $blank = static fn(string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $translations = $blank(BackendTranslations::class);
        $translations->addTranslation('sviat__ukrposhta__ship_api_malformed', 'Укрпошта відповіла незрозуміло.');

        $controller->fetch(
            new ShipmentService($ecom, $this->rows, $blank(SenderClient::class), $blank(RecipientParty::class), new AuthFlags(new InMemoryState())),
            $blank(ShipmentDrafts::class),
            $blank(ShipmentTracking::class),
            new Labels($ecom, $this->rows, $this->createStub(Settings::class), new AuthFlags(new InMemoryState())),
            $this->rows,
            $translations,
            $blank(OrderDeliveryLines::class),
        );
    }

    private function shipment(): void
    {
        $this->rows->add(7, ['uuid' => 'u-1', 'barcode' => '0500012345678', 'status' => 'CREATED', 'delivery_price' => 50.0, 'cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => true]);
    }

    private function signedIn(): string
    {
        $_SESSION['id'] = str_repeat('c', 64);

        return $_SESSION['id'];
    }

    public function testGetIsRejected(): void
    {
        $this->call('GET', ['action' => 'create', 'order_id' => '7']);

        self::assertSame(405, $this->answer['status']);
        self::assertSame(['ok' => false], $this->answer['body']);
        self::assertSame([], $this->rows->rows);
    }

    public function testMissingSessionRejectedAndNothingCreated(): void
    {
        foreach (['create', 'attach', 'remove', 'unlink', 'label'] as $action) {
            $this->shipment();
            $this->call('POST', ['action' => $action, 'order_id' => '7', 'barcode' => '0500012345679', 'weight' => '500']);

            self::assertSame(403, $this->answer['status'], $action);
            self::assertSame(['ok' => false], $this->answer['body'], $action);
            self::assertSame('0500012345678', $this->rows->forOrder(7)->barcode, $action);
            $this->call('POST', ['action' => $action, 'order_id' => '7', 'session_id' => str_repeat('a', 64)]);
            self::assertSame(403, $this->answer['status'], "{$action}: wrong token");
        }
        self::assertSame([], $this->transport->requests());
    }

    public function testUnknownOrderIsNotFound(): void
    {
        $this->call('POST', ['action' => 'label', 'order_id' => '8', 'session_id' => $this->signedIn()]);

        self::assertSame(404, $this->answer['status']);
        self::assertSame([], $this->transport->requests());
    }

    public function testLabelStreamsPdf(): void
    {
        $this->shipment();
        $this->transport->push(new HttpResponse(200, '%PDF-1.4 label'));

        $this->call('POST', ['action' => 'label', 'order_id' => '7', 'size' => 'SIZE_A4', 'session_id' => $this->signedIn()]);

        self::assertSame('%PDF-1.4 label', $this->answer['body']);
        self::assertContains('Content-Type: application/pdf', $this->answer['headers']);
        self::assertContains('Content-Disposition: attachment; filename="ukrposhta-0500012345678.pdf"', $this->answer['headers']);
        self::assertStringContainsString('size=SIZE_A4', $this->transport->requests()[0]->url);
    }

    public function testLabelErrorIsJson(): void
    {
        $this->shipment();
        $this->transport->json(200, ['message' => 'Sticker failed for token ' . self::TOKEN]);

        $this->call('POST', ['action' => 'label', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertSame(RESPONSE_JSON, $this->answer['type']);
        self::assertFalse($this->answer['body']['ok']);
        self::assertSame('Укрпошта відповіла незрозуміло.', $this->answer['body']['issues'][0]['text']);
        self::assertNotContains('Content-Type: application/pdf', $this->answer['headers']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($this->answer['body']), 'the token never reaches the browser');
    }

    public function testLabelWithoutShipmentIsJson(): void
    {
        $this->call('POST', ['action' => 'label', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertFalse($this->answer['body']['ok']);
        self::assertSame([], $this->transport->requests());
    }
}
