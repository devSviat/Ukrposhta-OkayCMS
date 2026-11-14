<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CitySearch;
use Okay\Modules\Sviat\Ukrposhta\Cart\OfficeList;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderParcel;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointChange;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointResult;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;

/**
 * Редактор пункту видачі на сторінці замовлення надсилає сюди (`controller=Sviat.Ukrposhta.OrderPointAdmin`).
 * Доступ потребує права `orders`; кожна дія — POST із CSRF-токеном.
 */
class OrderPointAdmin extends IndexAdmin
{
    private const int QUERY_LIMIT = 60;

    public function fetch(
        OrderPointChange $change,
        OrderParcel $parcel,
        CitySearch $cities,
        OfficeList $offices,
        CodPayments $codPayments,
        BackendTranslations $translations,
        OrderDeliveryLines $lines,
    ) {
        if (!$this->request->method('post')) {
            $this->answer(405, ['ok' => false]);

            return;
        }
        // Сток 4.5.3 перевіряє session_id лише після виконання контролера.
        if (!$this->request->checkSession()) {
            $this->answer(403, ['ok' => false]);

            return;
        }
        $action = (string)$this->request->post('action');

        if ($action === 'cities') {
            $this->answer(200, ['cities' => $cities->find($this->query('q'))]);

            return;
        }

        $orderId = (int)$this->request->post('order_id', 'integer');
        $order = $orderId > 0 ? $this->entityFactory->get(OrdersEntity::class)->get($orderId) : null;
        if (!$order) {
            $this->answer(404, ['ok' => false]);

            return;
        }

        if ($action === 'offices') {
            $groupId = (int)$this->request->post('group_id', 'integer');
            $this->answer(200, $offices->forCity(
                (int)$this->request->post('city_id', 'integer'),
                $parcel->metrics($order),
                $codPayments->isCod((int)($order->payment_method_id ?? 0)),
                $groupId > 0 ? $groupId : null,
                $this->query('q'),
            ));

            return;
        }

        $result = match ($action) {
            'save' => $change->save($orderId, [
                'city_id' => (string)$this->request->post('city_id', 'integer'),
                'office_id' => (string)$this->request->post('office_id', 'integer'),
            ]),
            'apply_price' => $change->applyPrice($orderId),
            default => null,
        };
        if ($result === null) {
            $this->answer(400, ['ok' => false]);

            return;
        }

        $body = self::view($result, $translations);
        if ($result->ok) {
            // Свіже замовлення: зміна пункту могла записати нову ціну доставки.
            $body['delivery'] = $lines->forOrder($this->entityFactory->get(OrdersEntity::class)->get($orderId));
        }
        $this->answer(200, $body);
    }

    private function query(string $name): string
    {
        $value = $this->request->post($name);

        return is_string($value) ? mb_substr($value, 0, self::QUERY_LIMIT) : '';
    }

    private static function view(OrderPointResult $result, BackendTranslations $translations): array
    {
        $text = static fn(?string $key): ?string => $key === null ? null : (string)$translations->getTranslation('sviat__ukrposhta__' . $key);

        return [
            'ok' => $result->ok,
            'error' => $text($result->error),
            'warning' => $text($result->warning),
            'price' => $result->price,
        ];
    }

    private function answer(int $status, array $body): void
    {
        $this->response->setStatusCode($status);
        $this->response->setContent(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), RESPONSE_JSON);
    }
}
