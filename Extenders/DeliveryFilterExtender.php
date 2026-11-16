<?php

namespace Okay\Modules\Sviat\Ukrposhta\Extenders;

use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Modules;
use Okay\Core\Modules\Module;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersListFilter;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentRows;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;

/** Ланки на DeliveryFilterHelper модуля Sviat/DeliveryFilter; Init реєструє їх лише тоді, коли хаб є серед файлів. */
class DeliveryFilterExtender implements ExtensionInterface
{
    public const string CARRIER = 'up';

    public function __construct(
        private readonly ShipmentRows $shipments,
        private readonly BackendTranslations $translations,
        private readonly Module $module,
        private readonly Modules $modules,
    ) {
    }

    /** ChainExtension для findDeliveryStatusFilters. */
    public function deliveryStatusFilters($filters)
    {
        if (!is_array($filters) || !$this->enabled()) {
            return $filters;
        }
        $filters[self::CARRIER] = [
            'title' => $this->text('sviat__ukrposhta__delivery_filter_title'),
            'short' => $this->text('sviat__ukrposhta__delivery_filter_short'),
            'options' => array_map($this->text(...), ShipmentStatus::FILTER_GROUPS),
        ];

        return $filters;
    }

    /** ChainExtension для filterByDeliveryStatus. */
    public function filterByDeliveryStatus($result, $filter = [], $carrier = '', $status = '')
    {
        if (!is_array($result) || $carrier !== self::CARRIER || !$this->enabled()) {
            return $result;
        }

        return OrdersListFilter::byStatus($result, $status, (int)$this->module->getModuleIdByNamespace(__CLASS__));
    }

    /** ChainExtension для findOrderIdsByShipmentNumber: замовлення з цим штрихкодом. */
    public function ordersByShipmentNumber($ids, $keyword = '')
    {
        $barcode = OrdersListFilter::barcode($keyword);
        $orderId = $barcode !== null && $this->enabled() ? $this->shipments->orderOfBarcode($barcode) : null;

        return is_array($ids) && $orderId !== null ? [...$ids, $orderId] : $ids;
    }

    /** Адмінка запускає init() і вимкнених модулів, тож ланки вимкненого перевізника зареєстровані й мусять мовчати. */
    private function enabled(): bool
    {
        return (bool)$this->modules->isActiveModule('Sviat', 'Ukrposhta');
    }

    private function text(string $key): string
    {
        return (string)$this->translations->getTranslation($key);
    }
}
