<?php

namespace Okay\Modules\Sviat\Ukrposhta\Extenders;

use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Ukrposhta\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointChange;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPaymentInput;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Shipments\LabelFile;
use Okay\Modules\Sviat\Ukrposhta\Shipments\PendingShipment;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentCard;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentRows;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentView;

class BackendExtender implements ExtensionInterface
{
    /** Тексти, які показує скрипт редактора пункту; ключі Backend/lang без префікса. */
    private const array EDITOR_TEXTS = [
        'order_city', 'order_point', 'order_cod_fee', 'order_cod_fee_hidden', 'order_price_fallback', 'op_city_placeholder', 'op_point_search', 'op_point_search_placeholder',
        'op_save', 'op_cancel', 'op_saving', 'op_change', 'op_no_points', 'op_choose_point', 'op_network',
        'op_unfit_too_heavy', 'op_unfit_cod_not_allowed', 'op_kind_office', 'op_kind_locker', 'op_kind_pudo',
        'op_price_keep', 'op_price_same', 'op_price_free', 'op_price_show', 'op_price_auto', 'op_price_offer', 'op_price_apply', 'op_price_applied',
    ];

    public function __construct(
        private readonly Design $design,
        private readonly Request $request,
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly BackendTranslations $translations,
        private readonly OrderDeliveryStore $orders,
        private readonly OrderRowsCleanup $orderRows,
        private readonly OrderDeliveryLines $orderLines,
        private readonly ShipmentRows $shipments,
        private readonly ShipmentView $shipmentView,
        private readonly OrdersMassAction $massAction,
        private readonly Response $response,
    ) {
    }

    /** ChainExtension для BackendPaymentsRequest::postPayment: ядро зберігає поле разом з рештою даних оплати. */
    public function paymentPost($paymentMethod)
    {
        if (is_object($paymentMethod)) {
            $post = [];
            foreach ([CodPaymentInput::MARKER, CodPaymentInput::SWITCH] as $name) {
                $post[$name] = $this->request->post($name);
            }
            $flag = CodPaymentInput::flag($post, $paymentMethod->module ?? null);
            if ($flag !== null) {
                $paymentMethod->{CodPayments::FIELD} = $flag;
            }
        }

        return $paymentMethod;
    }

    /** QueueExtension для BackendPaymentsHelper::getPaymentMethod: те, що показує payment_cod.tpl. */
    public function assignPaymentCod($payment, $id = null)
    {
        $manual = CodPayments::isManual(is_object($payment) ? ($payment->module ?? null) : null);
        $this->design->assign('up_codpay', [
            'on' => is_object($payment) && $manual && (int)($payment->{CodPayments::FIELD} ?? 0) === 1,
            'manual' => $manual,
        ]);
    }

    /** QueueExtension для BackendDeliveriesHelper::getDelivery: сторінка способу доставки попереджає, що ціни Укрпошти не буде. */
    public function deliveryWarning($delivery, $id = null): void
    {
        $ours = is_object($delivery) && DeliveryWarnings::freeForAll([$delivery], (int)$this->module->getModuleIdByNamespace(__CLASS__)) !== [];
        $this->design->assign('up_delivery_free_for_all', $ours);
    }

    /** QueueExtension для BackendOrdersHelper::delete (виконується до того, як ядро видалить замовлення). */
    public function deleteOrders($result, $ids): void
    {
        $this->orderRows->forOrders((array)$ids);
    }

    /** QueueExtension для BackendOrdersHelper::findOrder: сторінка замовлення показує й змінює пункт видачі та відправлення. */
    public function assignOrderDelivery($order, $orderId = null)
    {
        $found = is_object($order) && !empty($order->id);
        $own = $found && $this->orderLines->ownDelivery($order) !== null;
        $this->design->assign('up_order_delivery', $own ? $this->orderLines->forOrder($order) : null);
        $this->design->assign('up_order_point', $own ? $this->pointEditor($order) : null);
        $this->design->assign('up_shipment', $found ? $this->shipmentView->forOrder($order) : null);
    }

    /** Те, що потрібно редактору пункту; збережений пункт іде лише з номером, решту редактор питає сам. */
    private function pointEditor(object $order): array
    {
        $snapshot = $this->orders->forOrder((int)$order->id);
        $texts = [];
        foreach (self::EDITOR_TEXTS as $key) {
            $texts[$key] = (string)$this->translations->getTranslation('sviat__ukrposhta__' . $key);
        }

        return [
            'orderId' => (int)$order->id,
            'needsChoice' => $snapshot === null,
            'locked' => OrderPointChange::locks($this->shipments->forOrder((int)$order->id)),
            'cityId' => $snapshot ? (int)$snapshot->city_id : null,
            'cityName' => $snapshot ? (string)$snapshot->city_name : null,
            'officeId' => $snapshot ? (int)$snapshot->office_id : null,
            'currency' => (string)($this->entityFactory->get(CurrenciesEntity::class)->getMainCurrency()->sign ?? ''),
            'textsJson' => json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
    }

    /** ChainExtension для BackendOrdersHelper::buildFilter: дія списку (ядро виконує свої дії безпосередньо перед цим). */
    public function listFilter($filter)
    {
        if (is_array($filter)) {
            $this->handleMassAction();
        }

        return $filter;
    }

    private function handleMassAction(): void
    {
        if ($this->request->method('post')) {
            $result = $this->massAction->handleRequest(fn(): bool => (bool)$this->request->checkSession(), $_SESSION);
            if ($result instanceof LabelFile) {
                $this->sendFile($result);
            }
            if ($result === true) {
                Response::redirectTo($this->request->url());
            }

            return;
        }
        $taken = OrdersMassAction::take($_SESSION);
        $this->design->assign('up_mass_result', $taken === null ? null : OrdersMassAction::view(
            $taken,
            fn(string $key): string => (string)$this->translations->getTranslation($key),
        ));
    }

    /** Хук фільтра виконується посеред контролера списку: файл іде одразу, решта сторінки не рендериться. */
    private function sendFile(LabelFile $file): never
    {
        $this->response->setStatusCode(200)
            ->addHeader('Content-Type: ' . $file->type)
            ->addHeader('Content-Disposition: attachment; filename="' . $file->name . '"')
            ->addHeader('Cache-Control: no-store')
            ->addHeader('X-Content-Type-Options: nosniff')
            ->setContent($file->bytes, RESPONSE_TEXT)
            ->sendContent();
        exit;
    }

    /** QueueExtension для BackendOrdersHelper::findOrders: бейджі сторінки з бази одним запитом, без Укрпошти. */
    public function listOrders($orders, $filter = [])
    {
        if (!is_array($orders) || $orders === []) {
            return;
        }
        $rows = $this->shipments->forOrders(array_map(static fn($order): int => (int)$order->id, array_values($orders)));
        foreach ($orders as $order) {
            $row = $rows[(int)$order->id] ?? null;
            if ($row === null) {
                continue;
            }
            // Резервація чи невідоме створення: штрихкоду ще немає, але менеджер має побачити, що замовлення зайняте.
            if (PendingShipment::is($row)) {
                $order->up_shipment = ['barcode' => null, 'group' => 'unsettled', 'statusKey' => 'sviat__ukrposhta__list_unsettled', 'eventName' => null];
                continue;
            }
            $card = ShipmentCard::fromRow($row);
            $order->up_shipment = [
                'barcode' => $card['barcode'],
                'group' => $card['statusGroup'],
                'statusKey' => $card['statusKey'],
                'eventName' => $card['eventName'],
            ];
        }
    }
}
