<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

use Modules\Sviat\Ukrposhta\Support\TemplateSmarty;
use Okay\Core\BackendTranslations;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PersonName;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentView;
use PHPUnit\Framework\TestCase;

class ShipmentViewTest extends TestCase
{
    private const int NOW = 1_900_000_000;
    private const string XSS = '<img src=x onerror=alert(1)>';

    private static function row(array $override = []): object
    {
        return (object)($override + [
            'order_id' => 7, 'uuid' => 'u-1', 'sender_uuid' => 's-1', 'barcode' => '0500012345678', 'state' => PendingShipment::CREATED,
            'status' => 'CREATED', 'status_group' => 'created', 'event_code' => null, 'event_name' => null, 'event_at' => null, 'event_reason' => null,
            'delivery_price' => 55.0, 'cod_amount' => 1200.0, 'cod_fee' => 26.0, 'paid_by_recipient' => 1,
            'created_at' => '2030-03-17 10:00:00', 'checked_at' => null, 'reserved_at' => null,
        ]);
    }

    private static function draft(bool $paymentIsCod = false, string $officeKind = 'office', string $lastName = 'Шевченко'): ShipmentDraft
    {
        return new ShipmentDraft(
            'STANDARD', 1250, 30, 20, 10, 1200.0, $paymentIsCod, $paymentIsCod ? 1200.0 : 0.0, true, false, 'RETURN', 'Одяг', true,
            new PersonName($lastName, 'Тарас'), '380671234567', '01001', $officeKind, $paymentIsCod,
        );
    }

    private static function build(?object $row, ShipmentDraft|string|null $draft = null, bool $own = true, array $auth = []): ?array
    {
        return ShipmentView::build(7, $row, $own, static fn() => $draft ?? self::draft(), $auth, self::NOW);
    }

    public function testCardForUncertainOffersLookupAndAttach(): void
    {
        $uncertain = self::build(self::row(['state' => PendingShipment::UNCERTAIN, 'uuid' => null, 'barcode' => null, 'status' => null]));
        $stale = self::build(self::row(['state' => PendingShipment::PENDING, 'uuid' => null, 'barcode' => null, 'status' => null,
            'reserved_at' => date('Y-m-d H:i:s', self::NOW - PendingShipment::STALE_SECONDS - 1)]));

        foreach ([$uncertain, $stale] as $view) {
            self::assertSame('check', $view['mode']);
            self::assertSame(['lookup', 'attach', 'unlink'], $view['actions']);
            self::assertNull($view['card']);
            self::assertNull($view['form']);
        }

        $running = self::build(self::row(['state' => PendingShipment::PENDING, 'uuid' => null, 'barcode' => null, 'status' => null,
            'reserved_at' => date('Y-m-d H:i:s', self::NOW - 5)]));
        self::assertSame('pending', $running['mode']);
        self::assertSame([], $running['actions'], 'nothing to press while creation is running');
    }

    public function testDeleteOnlyWhenCreated(): void
    {
        self::assertSame(['label', 'refresh', 'remove', 'unlink'], self::build(self::row())['actions']);
        self::assertSame(['label', 'refresh', 'unlink'], self::build(self::row(['status' => 'REGISTERED', 'status_group' => 'accepted']))['actions']);
        self::assertSame(['label', 'refresh', 'unlink'], self::build(self::row(['status' => null, 'event_code' => 20700, 'status_group' => 'in_transit']))['actions']);
        self::assertContains('remove', self::build(self::row(['status' => null, 'event_code' => null, 'status_group' => null]))['actions'], 'attached, never tracked');
    }

    /** Lifecycle polling stops once tracking sees an event, so `status` stays CREATED on a parcel already on its way. */
    public function testNoDeleteOnceTrackingSawTheParcelMove(): void
    {
        foreach (['in_transit' => 20700, 'accepted' => 10100, 'received' => 41000, 'arrived' => 21700] as $group => $event) {
            self::assertNotContains('remove', self::build(self::row(['status' => 'CREATED', 'event_code' => $event, 'status_group' => $group]))['actions'], $group);
        }
        self::assertContains('remove', self::build(self::row(['status' => 'CREATED', 'event_code' => 10601, 'status_group' => 'created']))['actions']);
        self::assertNotContains('remove', self::build(self::row(['status' => 'REGISTERED', 'status_group' => 'created']))['actions']);
    }

    public function testCardShowsStatusSumsAndPayer(): void
    {
        $card = self::build(self::row(['status_group' => 'arrived', 'event_name' => 'Надійшло у відділення', 'event_at' => '2030-03-19 08:05:00']))['card'];

        self::assertSame('0500012345678', $card['barcode']);
        self::assertSame('arrived', $card['statusGroup']);
        self::assertSame('sviat__ukrposhta__status_arrived', $card['statusKey']);
        self::assertSame('Надійшло у відділення', $card['eventName']);
        self::assertSame('19.03.2030 08:05', $card['eventAt']);
        self::assertSame('55.00', $card['deliveryPrice']);
        self::assertSame('1200.00', $card['codAmount']);
        self::assertSame('26.00', $card['codFee']);
        self::assertSame('sviat__ukrposhta__party_recipient', $card['payerKey']);
    }

    public function testFormWithoutShipmentAndCodOnlyForCodPayment(): void
    {
        $prepaid = self::build(null, self::draft());
        self::assertSame('form', $prepaid['mode']);
        self::assertSame(['create', 'attach'], $prepaid['actions']);
        self::assertFalse($prepaid['form']['paymentIsCod']);
        self::assertSame('1250', $prepaid['form']['weight']);

        $cod = self::build(null, self::draft(paymentIsCod: true));
        self::assertTrue($cod['form']['paymentIsCod']);
        self::assertSame('1200.00', $cod['form']['codAmount']);
    }

    public function testDraftProblemsAreShownBeforeCreating(): void
    {
        $view = self::build(null, self::draft(paymentIsCod: true, officeKind: 'locker', lastName: ''));

        self::assertSame('sviat__ukrposhta__ship_err_cod_locker', $view['issues']['cod']);
        self::assertSame('sviat__ukrposhta__ship_err_name', $view['issues']['name']);
    }

    public function testOrderWithoutPointShowsRefusalAndAttach(): void
    {
        $view = self::build(null, 'sviat__ukrposhta__ship_err_no_point');

        self::assertSame('refusal', $view['mode']);
        self::assertSame('sviat__ukrposhta__ship_err_no_point', $view['refusalKey']);
        self::assertSame(['attach'], $view['actions']);
    }

    public function testForeignOrderWithoutShipmentShowsNothingButKeepsItsShipment(): void
    {
        self::assertNull(self::build(null, own: false));
        self::assertSame('card', self::build(self::row(), own: false)['mode'], 'switched delivery: the shipment still has to be deleted');
    }

    public function testTemplatesRenderEveryModeEscaped(): void
    {
        $auth = [['api' => 'ecom', 'reason' => 'bad_bearer']];
        $check = self::render('shipment_side.tpl', self::build(self::row(['state' => PendingShipment::UNCERTAIN, 'uuid' => null, 'barcode' => null]), auth: $auth));
        self::assertStringContainsString('fn_up_ship_lookup', $check);
        self::assertStringContainsString('fn_up_ship_attach', $check);
        self::assertStringContainsString('могло вже з', $check, 'unlink of an uncertain shipment warns about a duplicate');
        self::assertStringContainsString('не приймає ключі', $check);

        $card = self::render('shipment_main.tpl', self::build(self::row(['event_name' => self::XSS])));
        self::assertStringContainsString('fn_up_ship_label', $card);
        self::assertStringContainsString('fn_up_ship_remove', $card);
        self::assertStringNotContainsString(self::XSS, $card);
        self::assertStringContainsString('&lt;img src=x', $card);
        self::assertSame('', trim(self::render('shipment_side.tpl', self::build(self::row()))), 'the card lives under the products');

        $form = self::render('shipment_side.tpl', self::build(null, self::draft(paymentIsCod: true)));
        self::assertStringContainsString('data-field="cod_amount"', $form);
        self::assertStringNotContainsString('name="weight"', $form, 'fields stay out of the order form');
        self::assertStringNotContainsString('data-field="cod_amount"', self::render('shipment_side.tpl', self::build(null, self::draft())));

        foreach ([$check, $card, $form] as $html) {
            self::assertStringNotContainsString('sviat__ukrposhta__', $html, 'every translation key exists');
        }
    }

    /** Прострочена сесія дає 403 з тілом без issues: блок має сказати оновити сторінку, а не мовчати. */
    public function testRefusedRequestTellsToReloadThePage(): void
    {
        $html = self::render('shipment_main.tpl', self::build(self::row()));
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/js/shipment.js');

        self::assertStringContainsString('data-expired="Сесія завершилась або сторінка застаріла. Оновіть сторінку й повторіть дію."', $html);
        self::assertStringContainsString('data-unexpected="Непередбачена помилка на сервері магазину. Спробуйте ще раз."', $html);
        self::assertSame(2, substr_count($js, '= answerKind(response)'), 'both the action and the label request classify the answer');
        // Вхід адмінки відповідає 200 з HTML після редиректу: це теж прострочена сесія, а 5xx — збій сервера.
        self::assertStringContainsString('response.redirected', $js);
        self::assertStringContainsString('response.status >= 500', $js);
        self::assertStringContainsString("getAttribute('data-expired')", $js);
        self::assertStringContainsString("getAttribute('data-unexpected')", $js);
    }

    private static function render(string $template, ?array $view): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/../Support/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-ukrposhta-ship-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        $lang = [];
        include $root . '/Okay/Modules/Sviat/Ukrposhta/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SESSION['id'] ??= 'test-session';
        $smarty->assign('up_shipment', $view);

        return $smarty->fetch($template);
    }
}
