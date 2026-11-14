<?php

namespace Modules\Sviat\Ukrposhta\Orders;

require_once __DIR__ . '/../Support/MassWorld.php';

use Modules\Sviat\Ukrposhta\Support\MassWorld;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Ukrposhta\Shipments\LabelFile;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassReport;
use PHPUnit\Framework\TestCase;

class OrdersMassActionTest extends TestCase
{
    use MassWorld;

    private const string PDF = "%PDF-1.4\nlabel";

    protected function setUp(): void
    {
        $this->setUpWorld();
    }

    private function action(): OrdersMassAction
    {
        return new OrdersMassAction($this->mass(), $this->labels(), $this->logger);
    }

    private static function post(string $action, array $ids = ['301', '302']): array
    {
        return ['action' => $action, 'check' => $ids];
    }

    public function testIgnoredWithoutSession(): void
    {
        $this->createdRow(301, '0500000000017');
        foreach ([OrdersMassAction::CREATE, OrdersMassAction::PRINT] as $action) {
            $session = [];

            self::assertFalse($this->action()->handle(self::post($action), false, $session));
            self::assertSame([], $session);
        }
        self::assertSame([], $this->transport->requests());
        self::assertNull($this->rows->forOrder(302));
    }

    public function testOtherActionsAndMissingIdsAreIgnored(): void
    {
        $session = [];

        self::assertFalse($this->action()->handle(self::post('delete'), true, $session));
        self::assertFalse($this->action()->handle(['action' => OrdersMassAction::CREATE, 'check' => '301'], true, $session));
        self::assertFalse($this->action()->handle(['action' => OrdersMassAction::CREATE], true, $session));
        self::assertSame([], $session);
    }

    public function testStockSessionCheckThatDropsPostDoesNotCrash(): void
    {
        $saved = $_POST;
        $_POST = self::post(OrdersMassAction::CREATE);
        $session = [];
        try {
            $handled = $this->action()->handleRequest(static function (): bool {
                // Request::checkSession() стоку 4.5.3 на хибному токені.
                unset($_POST);

                return false;
            }, $session);
        } finally {
            $_POST = $saved;
        }

        self::assertFalse($handled);
        self::assertSame([], $session);
    }

    public function testLimitTwenty(): void
    {
        $this->foreign = range(1, 100);
        $session = ['other' => 1];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::CREATE, array_map(strval(...), range(1, 25))), true, $session));
        $taken = OrdersMassAction::take($session);

        self::assertInstanceOf(MassReport::class, $taken['report']);
        self::assertSame(range(1, 20), array_column($taken['report']->rows, 'orderId'));
        self::assertSame(5, $taken['report']->untouched);
        self::assertSame(['other' => 1], $session, 'taken once');
        self::assertNull(OrdersMassAction::take($session));
        self::assertSame([], $this->transport->requests());
    }

    public function testPrintStreamsOnePdf(): void
    {
        $this->createdRow(301, '0500000000017');
        $this->transport->push(new HttpResponse(200, self::PDF));
        $session = [];

        $file = $this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session);

        self::assertInstanceOf(LabelFile::class, $file);
        self::assertSame('application/pdf', $file->type);
        self::assertSame(self::PDF, $file->bytes);
        self::assertSame([], $session);
    }

    public function testPrintOfTwoPayersIsOneZipWithBoth(): void
    {
        $this->createdRow(301, '0500000000017', true);
        $this->createdRow(302, '0500000000024', false);
        $this->transport->push(new HttpResponse(200, self::PDF . '-recipient'), new HttpResponse(200, self::PDF . '-sender'));
        $session = [];

        $file = $this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session);

        self::assertInstanceOf(LabelFile::class, $file);
        self::assertSame('application/zip', $file->type);
        self::assertStringEndsWith('.zip', $file->name);
        $path = tempnam(sys_get_temp_dir(), 'up-zip-test');
        file_put_contents($path, $file->bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $contents[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);
        self::assertSame([self::PDF . '-recipient', self::PDF . '-sender'], array_values($contents));
        foreach (array_keys($contents) as $name) {
            self::assertMatchesRegularExpression('/^ukrposhta-labels-[\w-]+\.pdf$/', $name);
        }
    }

    public function testPrintWithoutShipmentsReportsInsteadOfStreaming(): void
    {
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        self::assertSame(['key' => 'sviat__ukrposhta__label_err_no_shipment', 'details' => null], OrdersMassAction::take($session)['error']);
        self::assertSame([], $this->transport->requests());
    }

    public function testPrintOverHundredIsRefused(): void
    {
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT, array_map(strval(...), range(1, 101))), true, $session));

        self::assertSame('sviat__ukrposhta__label_err_too_many', OrdersMassAction::take($session)['error']['key']);
    }

    public function testLabelErrorBodyIsReportedNotStreamed(): void
    {
        $this->createdRow(301, '0500000000017');
        $this->transport->json(200, ['message' => 'Shipment not found']);
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        $error = OrdersMassAction::take($session)['error'];
        self::assertStringStartsWith('sviat__ukrposhta__ship_api_', $error['key']);
    }

    /** Збій поза Укрпоштою (БД, zip) не дає 500: менеджер повертається до списку з повідомленням. */
    public function testUnexpectedPrintFailureIsReportedAndLoggedMasked(): void
    {
        $this->createdRow(301, '0500000000017');
        $this->transport->push(new \RuntimeException('socket closed for ?token=cp-token-1234'));
        $session = [];

        self::assertTrue($this->action()->handle(self::post(OrdersMassAction::PRINT), true, $session));

        self::assertSame(['key' => 'sviat__ukrposhta__label_err_generic', 'details' => null], OrdersMassAction::take($session)['error']);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('RuntimeException', $this->logger->text());
        self::assertStringNotContainsString('cp-token-1234', $this->logger->text());
    }

    public function testViewTranslatesRowsAndError(): void
    {
        $texts = [
            'sviat__ukrposhta__mass_title' => 'створено %1$s з %2$s',
            'sviat__ukrposhta__mass_created' => 'відправлення %s',
            'sviat__ukrposhta__mass_skipped' => 'пропущено',
            'sviat__ukrposhta__mass_failed' => 'Укрпошта відмовила',
            'sviat__ukrposhta__mass_has_shipment' => 'відправлення вже є:',
            'sviat__ukrposhta__ship_err_phone' => 'Перевірте телефон.',
            'sviat__ukrposhta__mass_untouched' => 'до %1$s; решту %2$s не чіпали',
            'sviat__ukrposhta__label_err_no_shipment' => 'Ярликів немає.',
        ];
        $text = static fn(string $key): string => $texts[$key];
        $report = new MassReport([
            ['orderId' => 1, 'kind' => 'created', 'key' => null, 'value' => '0500000000017'],
            ['orderId' => 2, 'kind' => 'skipped', 'key' => 'sviat__ukrposhta__mass_has_shipment', 'value' => '0500000000024'],
            ['orderId' => 3, 'kind' => 'failed', 'key' => 'sviat__ukrposhta__ship_err_phone', 'value' => 'Recipient phone is invalid'],
        ], 4);

        $view = OrdersMassAction::view(['report' => $report, 'error' => null], $text);

        self::assertSame('створено 1 з 3', $view['title']);
        self::assertSame([
            ['orderId' => 1, 'kind' => 'created', 'text' => 'відправлення 0500000000017'],
            ['orderId' => 2, 'kind' => 'skipped', 'text' => 'пропущено: відправлення вже є: 0500000000024'],
            ['orderId' => 3, 'kind' => 'failed', 'text' => 'Укрпошта відмовила: Перевірте телефон. (Recipient phone is invalid)'],
        ], $view['rows']);
        self::assertSame('до 20; решту 4 не чіпали', $view['untouched']);
        self::assertNull($view['error']);

        $error = OrdersMassAction::view(['report' => null, 'error' => ['key' => 'sviat__ukrposhta__label_err_no_shipment', 'details' => null]], $text);
        self::assertSame('Ярликів немає.', $error['error']);
        self::assertSame([], $error['rows']);
    }

    public function testForgedSessionIsDropped(): void
    {
        $session = ['sviat_up_mass' => ['report' => ['rows' => [['orderId' => '1<script>', 'kind' => 'created']], 'untouched' => 0]]];

        self::assertNull(OrdersMassAction::take($session));
        self::assertSame([], $session);
    }
}
