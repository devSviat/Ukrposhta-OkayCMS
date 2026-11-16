<?php

namespace Modules\Sviat\Ukrposhta\Orders;

require_once __DIR__ . '/../Support/InMemoryShipmentRows.php';

use Modules\Sviat\Ukrposhta\Support\InMemoryShipmentRows;
use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Modules;
use Okay\Core\Modules\Module;
use Okay\Modules\Sviat\DeliveryFilter\Helpers\DeliveryStatusGroups;
use Okay\Modules\Sviat\Ukrposhta\Extenders\BackendExtender;
use Okay\Modules\Sviat\Ukrposhta\Extenders\DeliveryFilterExtender;
use Okay\Modules\Sviat\Ukrposhta\Orders\DeliveryFilterHub;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use PHPUnit\Framework\TestCase;

/** Укрпошта в списку замовлень — лише через хаб Sviat/DeliveryFilter: група `up` і пошук штрихкодом. */
class DeliveryFilterHooksTest extends TestCase
{
    private const string HUB = 'Okay\\Modules\\Sviat\\DeliveryFilter\\Helpers\\DeliveryFilterHelper';
    private const int MODULE = 42;

    private InMemoryShipmentRows $rows;

    protected function setUp(): void
    {
        $this->rows = new InMemoryShipmentRows();
        $this->rows->rows[77] = (object)['barcode' => '0500000000017'];
        $this->rows->rows[78] = (object)['barcode' => 'RR123456789UA'];
    }

    private function hooks(string $label = 'ua', bool $active = true): DeliveryFilterExtender
    {
        $lang = [];
        include dirname(__DIR__, 5) . "/Okay/Modules/Sviat/Ukrposhta/Backend/lang/{$label}.php";
        $translations = $this->createStub(BackendTranslations::class);
        $translations->method('getTranslation')->willReturnCallback(static fn(string $key): string => $lang[$key] ?? '');
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);

        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn($vendor, $name): bool => $active && $vendor === 'Sviat' && $name === 'Ukrposhta');

        return new DeliveryFilterExtender($this->rows, $translations, $module, $modules);
    }

    public function testGroupDescribesEveryFilterGroup(): void
    {
        $filters = $this->hooks()->deliveryStatusFilters(['np' => []]);

        self::assertSame(['np', 'up'], array_keys($filters));
        self::assertSame('Укрпошта', $filters['up']['title']);
        self::assertSame('УП', $filters['up']['short']);
        self::assertArrayNotHasKey('legacy_param', $filters['up'], 'Укрпошта не передає старий параметр: редиректу зі старих закладок немає');
        self::assertSame(array_keys(ShipmentStatus::FILTER_GROUPS), array_keys($filters['up']['options']));
        self::assertSame('У відділенні', $filters['up']['options']['arrived']);
        self::assertSame('Без відправлення', $filters['up']['options']['none']);
    }

    public function testGroupPassesTheHubWhitelist(): void
    {
        if (!class_exists(DeliveryStatusGroups::class)) {
            self::markTestSkipped('хаба Sviat/DeliveryFilter тут немає');
        }
        foreach (['ua', 'en', 'ru'] as $label) {
            self::assertSame(['up'], array_keys(DeliveryStatusGroups::sanitize($this->hooks($label)->deliveryStatusFilters([]))), $label);
        }
    }

    public function testStatusBecomesTheSameEntityFilterAsBefore(): void
    {
        self::assertSame(['status_id' => 2, 'up_status_groups' => ['arrived']], $this->hooks()->filterByDeliveryStatus(['status_id' => 2], [], 'up', 'arrived'));
        self::assertSame(['up_without_shipment' => self::MODULE], $this->hooks()->filterByDeliveryStatus([], [], 'up', 'none'));
        foreach (array_keys(ShipmentStatus::FILTER_GROUPS) as $group) {
            self::assertNotSame([], $this->hooks()->filterByDeliveryStatus([], [], 'up', $group), $group);
        }
    }

    public function testOtherCarrierAndUnknownStatusAreLeftAlone(): void
    {
        self::assertSame(['np_status_codes' => [7, 8]], $this->hooks()->filterByDeliveryStatus(['np_status_codes' => [7, 8]], [], 'np', 'arrived'));
        self::assertSame([], $this->hooks()->filterByDeliveryStatus([], [], 'up', "arrived' OR 1=1"));
    }

    public function testBarcodeIsFound(): void
    {
        self::assertSame([3, 77], $this->hooks()->ordersByShipmentNumber([3], ' 05000 0000 0017 '));
        self::assertSame([78], $this->hooks()->ordersByShipmentNumber([], 'rr123456789ua'));
    }

    public function testAnythingElseFindsNothing(): void
    {
        foreach (['Олена', '0509999999999', '20451234567890', '77', ''] as $keyword) {
            self::assertSame([5], $this->hooks()->ordersByShipmentNumber([5], $keyword), $keyword);
        }
    }

    /** Свого фільтра й лічильників у модуля більше немає: на buildFilter лишилась тільки масова дія. */
    public function testNoOwnFilterOrCounts(): void
    {
        self::assertFalse(method_exists(BackendExtender::class, 'countFilter'));
        $init = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Init/Init.php');
        self::assertStringNotContainsString('buildCountStatusesFilter', $init);
        self::assertStringContainsString('DeliveryFilterHub::hooks(DeliveryFilterHub::HELPER)', $init);
    }

    public function testInitDoesNotRegisterHubHooksWithoutClass(): void
    {
        self::assertSame([], DeliveryFilterHub::hooks(self::HUB . 'Missing'));
        self::assertSame(self::HUB, DeliveryFilterHub::HELPER);
        self::assertSame([
            [[HubWithAllMethods::class, 'findDeliveryStatusFilters'], [DeliveryFilterExtender::class, 'deliveryStatusFilters']],
            [[HubWithAllMethods::class, 'filterByDeliveryStatus'], [DeliveryFilterExtender::class, 'filterByDeliveryStatus']],
            [[HubWithAllMethods::class, 'findOrderIdsByShipmentNumber'], [DeliveryFilterExtender::class, 'ordersByShipmentNumber']],
        ], DeliveryFilterHub::hooks(HubWithAllMethods::class));
    }

    /** Select, приховане поле й підпис під пошуком тепер дає хаб. */
    public function testNoOwnFilterTemplates(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta';
        $json = (string)file_get_contents($root . '/Init/module.json');
        foreach (['orders_status_filter.tpl', 'orders_search.tpl', 'orders_search_hint.tpl'] as $file) {
            self::assertStringNotContainsString('"' . $file . '"', $json);
            self::assertFileDoesNotExist($root . '/Backend/design/html/' . $file);
        }
        foreach (['{if $labels}', '<form class=\\"search\\" method=\\"get\\">', 'input-group--search'] as $anchor) {
            self::assertStringNotContainsString($anchor, $json);
        }
        $lang = [];
        include $root . '/Backend/lang/ua.php';
        self::assertSame([], array_values(array_filter(array_keys($lang), static fn(string $k): bool => str_contains($k, 'list_filter') || str_contains($k, 'list_search_hint'))));
    }

    /** Адмінка запускає init() і вимкнених модулів: ланки вимкненого перевізника зареєстровані, але мусять мовчати. */
    public function testDisabledCarrierAddsNothing(): void
    {
        $hooks = $this->hooks('ua', false);

        self::assertSame(['xx' => []], $hooks->deliveryStatusFilters(['xx' => []]));
        self::assertSame(['page' => 1], $hooks->filterByDeliveryStatus(['page' => 1], ['page' => 1], 'up', 'arrived'));
        self::assertSame([5], $hooks->ordersByShipmentNumber([5], ' 05000 0000 0017 '));
    }

    /** Новіший перевізник поруч зі старішим хабом, де ще немає якоїсь ланки: реєстрація кинула б виняток в init(). */
    public function testNoHooksWhenTheHubLacksAMethod(): void
    {
        self::assertSame([], DeliveryFilterHub::hooks(HubWithoutSearch::class));
    }
}

class HubWithAllMethods
{
    public function findDeliveryStatusFilters(): array
    {
        return [];
    }

    public function filterByDeliveryStatus(array $filter, string $carrier, string $status): array
    {
        return $filter;
    }

    public function findOrderIdsByShipmentNumber(string $keyword): array
    {
        return [];
    }
}

class HubWithoutSearch
{
    public function findDeliveryStatusFilters(): array
    {
        return [];
    }

    public function filterByDeliveryStatus(array $filter, string $carrier, string $status): array
    {
        return $filter;
    }
}
