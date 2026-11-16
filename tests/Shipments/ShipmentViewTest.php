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

    private static function build(?object $row, ShipmentDraft|string|null $draft = null, bool $own = true, array $auth = [], ?string $point = null): ?array
    {
        return ShipmentView::build(7, $row, $own, static fn() => $draft ?? self::draft(), $auth, self::NOW, point: $point);
    }

    private static function details(array $override = []): string
    {
        return (string)json_encode($override + [
            'type' => 'STANDARD', 'description' => 'Одяг', 'on_fail' => 'RETURN', 'last_name' => 'Шевченко', 'first_name' => 'Тарас',
            'middle_name' => 'Григорович', 'phone' => '380671234567', 'postcode' => '01001', 'delivery_date' => '2030-03-20',
            'weight_g' => 1250, 'length' => 30, 'width' => 20, 'height' => 10, 'declared' => 1200.0, 'sms' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function history(array $events): string
    {
        return (string)json_encode(array_map(
            static fn(array $e): array => $e + ['reason' => null],
            $events,
        ), JSON_UNESCAPED_UNICODE);
    }

    private static function tracked(array $override = []): object
    {
        return self::row($override + [
            'status' => 'REGISTERED', 'status_group' => 'arrived', 'event_code' => 21700, 'event_name' => 'Надійшло у відділення', 'event_at' => '2030-03-19 08:05:00',
            'details' => self::details(),
            'history' => self::history([
                ['code' => 10100, 'name' => 'Прийнято', 'place' => '79007 Львів 7', 'at' => '2030-03-17 12:00:00'],
                ['code' => 21700, 'name' => 'Надійшло у відділення', 'place' => '01001 Київ 1', 'at' => '2030-03-19 08:05:00'],
            ]),
        ]);
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
        self::assertSame('Надійшло у відділення', $card['event']['name']);
        self::assertSame('19.03.2030 08:05', $card['event']['at']);
        self::assertSame('55.00', $card['deliveryPrice']);
        self::assertSame('1200.00', $card['codAmount']);
        self::assertSame('26.00', $card['codFee']);
        self::assertSame('sviat__ukrposhta__party_recipient', $card['payerKey']);
    }

    public function testCardShowsTheSnapshotAndTheHistory(): void
    {
        $card = self::build(self::tracked(), point: '01001 Київ 1, вул. Хрещатик, 22')['card'];

        self::assertSame(['name' => 'Надійшло у відділення', 'place' => '01001 Київ 1', 'at' => '19.03.2030 08:05'], $card['event']);
        self::assertSame('Шевченко Тарас Григорович', $card['recipient']);
        self::assertSame('+380 67 123 45 67', $card['phone']);
        self::assertSame('01001 Київ 1, вул. Хрещатик, 22', $card['point']);
        self::assertSame('20.03.2030', $card['plannedDate']);
        self::assertSame('1200.00', $card['declared']);
        self::assertSame([
            'typeKey' => 'sviat__ukrposhta__shipping_type_standard', 'weight' => '1250', 'sides' => '30×20×10',
            'description' => 'Одяг', 'onFailKey' => 'sviat__ukrposhta__shipping_on_fail_return', 'sms' => true,
        ], $card['more']);
        self::assertSame(['Надійшло у відділення', 'Прийнято'], array_column($card['history'], 'name'), 'newest first');
        self::assertSame('17.03.2030 12:00', $card['history'][1]['at']);
    }

    /** Порожнє поле картка не показує зовсім, а не друкує «—». */
    public function testCardWithoutSnapshotHasNoEmptyFields(): void
    {
        $card = self::build(self::row(['cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => null]))['card'];

        foreach (['event', 'recipient', 'phone', 'point', 'plannedDate', 'declared', 'codAmount', 'codFee', 'payerKey'] as $key) {
            self::assertNull($card[$key], $key);
        }
        self::assertSame([], $card['more']);
        self::assertSame([], $card['history']);
        $html = self::render('shipment_main.tpl', self::build(self::row(['cod_amount' => null, 'cod_fee' => null, 'paid_by_recipient' => null])));
        self::assertStringNotContainsString('—', $html);
        self::assertStringNotContainsString('Одержувач', $html);
        self::assertStringNotContainsString('Показати більше', $html, 'nothing to show more of');
        self::assertStringContainsString('Статус ще не перевіряли', $html);
    }

    public function testBrokenJsonGivesACardWithoutThoseFields(): void
    {
        $card = self::build(self::tracked(['details' => '{"description": ', 'history' => '[{"code": "x"}]']))['card'];

        self::assertSame('Надійшло у відділення', $card['event']['name']);
        self::assertNull($card['event']['place']);
        self::assertNull($card['recipient']);
        self::assertSame([], $card['history']);
    }

    /** Пункт зі знімка замовлення лише тоді, коли відправлення йде саме туди. */
    public function testPointOnlyWhenTheOrdersPointIsTheShipmentsPostcode(): void
    {
        self::assertSame('02002', self::build(self::tracked(['details' => self::details(['postcode' => '02002'])]), point: '01001 Київ 1')['card']['point']);
        self::assertNull(self::build(self::tracked(['details' => self::details(['postcode' => null])]), point: '01001 Київ 1')['card']['point'], 'attached elsewhere: unknown');
    }

    public function testPlannedDateGoesOnceTheParcelIsDone(): void
    {
        self::assertNull(self::build(self::tracked(['status_group' => 'received', 'event_code' => 41000]))['card']['plannedDate']);
        self::assertNull(self::build(self::tracked(['status_group' => 'returned']))['card']['plannedDate']);
    }

    public function testCodOnlyWhenThereIsOne(): void
    {
        $html = self::render('shipment_main.tpl', self::build(self::tracked(['cod_amount' => null, 'cod_fee' => null])));
        $cod = self::render('shipment_main.tpl', self::build(self::tracked()));

        self::assertStringNotContainsString('Післяплата', $html);
        self::assertStringContainsString('Післяплата', $cod);
        self::assertStringContainsString('1200.00', $cod);
    }

    /**
     * Уся накладна в рамці, як у NP: шапка, тіло, смуга «Показати більше» й футер із діями — всередині рамки,
     * у цьому порядку; розгорнутий блок прихований, доки його не відкриють кнопкою футера.
     */
    public function testCardIsOneFrameWithHeaderBodyMoreAndFooter(): void
    {
        $html = self::render('shipment_main.tpl', self::build(self::tracked(), point: '01001 Київ 1'));
        $frame = substr($html, (int)strpos($html, '<div class="up-card">'));
        $positions = array_map(static fn(string $part): int|false => strpos($frame, $part), [
            'class="up-card__top"', 'class="up-card__body"', 'class="up-card__more" hidden', 'class="up-card__footer"', 'fn_up_ship_more', 'fn_up_ship_label', 'fn_up_ship_refresh', 'fn_up_ship_unlink',
        ]);

        self::assertNotContains(false, $positions);
        self::assertSame(array_slice($positions, 0, 4), array_values(array_unique(array_slice($positions, 0, 4))));
        self::assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3], 'top, body, more, footer');
        self::assertGreaterThan($positions[3], $positions[4], 'the toggle lives in the footer');
        self::assertGreaterThan($positions[3], $positions[5], 'the actions live in the footer');
        self::assertStringContainsString('Створено 17.03.2030 10:00', strip_tags($frame), 'created date sits in the header, like NP');
        self::assertStringContainsString('data-less="Приховати"', $frame);
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/js/shipment.js');
        self::assertStringContainsString("closest('.fn_up_ship_more')", $js);
    }

    /** Публічної сторінки трекінгу Укрпошти документація не підтверджує: посилання на неї картка не дає. */
    public function testCardHasNoUnconfirmedTrackingLink(): void
    {
        $html = self::render('shipment_main.tpl', self::build(self::tracked()));

        self::assertStringNotContainsString('track.ukrposhta', $html);
        self::assertStringContainsString('data-copy="0500012345678"', $html);
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

        $card = self::render('shipment_main.tpl', self::build(self::tracked([
            'status_group' => 'created', 'status' => 'CREATED', 'event_name' => self::XSS,
            'details' => self::details(['description' => self::XSS, 'last_name' => self::XSS, 'phone' => self::XSS]),
            'history' => self::history([['code' => 21700, 'name' => self::XSS, 'place' => self::XSS, 'at' => '2030-03-19 08:05:00']]),
        ]), point: self::XSS));
        self::assertStringContainsString('fn_up_ship_label', $card);
        self::assertStringContainsString('fn_up_ship_remove', $card);
        self::assertStringNotContainsString(self::XSS, $card);
        // Подія, місце (і в рядку події, і в історії), опис, ПІБ, телефон.
        self::assertGreaterThanOrEqual(6, substr_count($card, '&lt;img src=x'));
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

    /** Ярлик відкривається в новій вкладці лише як blob PDF і без доступу вкладки до сторінки замовлення; JS не вставляє розмітку. */
    public function testLabelTabIsDetachedAndScriptInsertsNoMarkup(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/js/shipment.js');

        self::assertMatchesRegularExpression("~var tab = window\\.open\\('', '_blank'\\);\\s*if \\(tab\\) \\{\\s*tab\\.opener = null;~", $js);
        self::assertStringContainsString("new Blob([bytes], { type: 'application/pdf' })", $js);
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
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
