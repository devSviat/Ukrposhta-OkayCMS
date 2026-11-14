<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;

/**
 * Що показує блок відправлення на сторінці замовлення: форму нового, перевірку невідомого створення
 * або картку наявного. `actions` — кнопки, які шаблон малює; контролер усе одно перевіряє кожну дію сам.
 */
final class ShipmentView
{
    public function __construct(
        private readonly OrderDeliveryLines $lines,
        private readonly ShipmentRows $rows,
        private readonly ShipmentDrafts $drafts,
        private readonly AuthFlags $auth,
        private readonly Labels $labels,
    ) {
    }

    /** @return ?array null — блок не показується: доставка не наша і відправлення немає */
    public function forOrder(object $order): ?array
    {
        $orderId = (int)($order->id ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        return self::build(
            $orderId,
            $this->rows->forOrder($orderId),
            $this->lines->ownDelivery($order) !== null,
            fn(): ShipmentDraft|string => $this->drafts->forOrder($orderId),
            $this->auth->items(),
            time(),
            $this->labels->defaultSize(),
        );
    }

    /**
     * @param \Closure(): (ShipmentDraft|string) $draft чернетка потрібна лише формі, а будується з товарів замовлення
     * @param list<array{api: string, reason: string}> $auth AuthFlags::items()
     */
    public static function build(int $orderId, ?object $row, bool $own, \Closure $draft, array $auth, int $now, string $labelSize = Labels::SIZE_10X10): ?array
    {
        $view = self::state($orderId, $row, $own, $draft, $now);
        if ($view === null) {
            return null;
        }

        // Smarty 5 не викликає in_array() без реєстрації: шаблон питає `can.<дія>`.
        return $view + ['can' => array_fill_keys($view['actions'], true), 'auth' => $auth, 'labelSize' => $labelSize];
    }

    /** @param \Closure(): (ShipmentDraft|string) $draft */
    private static function state(int $orderId, ?object $row, bool $own, \Closure $draft, int $now): ?array
    {
        // Після зміни способу доставки відправлення старого пункту лишається видимим: його треба видалити чи відв'язати.
        if ($row === null && !$own) {
            return null;
        }
        $view = [
            'orderId' => $orderId,
            'mode' => 'form',
            'actions' => [],
            'card' => null,
            'form' => null,
            'issues' => [],
            'refusalKey' => null,
        ];

        if (PendingShipment::needsCheck($row, $now)) {
            return ['mode' => 'check', 'actions' => ['lookup', 'attach', 'unlink']] + $view;
        }
        if (PendingShipment::running($row, $now)) {
            return ['mode' => 'pending', 'refusalKey' => 'sviat__ukrposhta__ship_err_pending'] + $view;
        }
        if ($row !== null) {
            $actions = ['label', 'refresh', ...(ShipmentService::deletable($row) ? ['remove'] : []), 'unlink'];

            return ['mode' => 'card', 'card' => ShipmentCard::fromRow($row), 'actions' => $actions] + $view;
        }

        $built = $draft();
        if (is_string($built)) {
            return ['mode' => 'refusal', 'refusalKey' => $built, 'actions' => ['attach']] + $view;
        }

        return ['form' => self::form($built), 'issues' => $built->errors(), 'actions' => ['create', 'attach']] + $view;
    }

    /** @return array<string, string|bool> */
    private static function form(ShipmentDraft $draft): array
    {
        $money = static fn(float $value): string => number_format($value, 2, '.', '');

        return [
            'type' => $draft->type,
            'weight' => (string)$draft->weightG,
            'length' => (string)$draft->lengthCm,
            'width' => (string)$draft->widthCm,
            'height' => (string)$draft->heightCm,
            'declared' => $money($draft->declaredPrice),
            'cod' => $draft->cod,
            'codAmount' => $money($draft->codAmount),
            'payer' => $draft->paidByRecipient ? 'recipient' : 'sender',
            'codPayer' => $draft->codPaidByRecipient ? 'recipient' : 'sender',
            'onFail' => $draft->onFail,
            'description' => $draft->description,
            'sms' => $draft->sms,
            'lastName' => $draft->name->last,
            'firstName' => $draft->name->first,
            'middleName' => $draft->name->middle,
            'phone' => $draft->phone,
            'officePostcode' => $draft->officePostcode,
            'toLocker' => $draft->toLocker(),
            'paymentIsCod' => $draft->paymentIsCod,
        ];
    }
}
