<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;

/** Ярлики відправлень: байти PDF віддає адмін-контролер, токен не потрапляє в браузер. */
final class Labels
{
    public const string SIZE_10X10 = 'SIZE_10X10';
    public const string SIZE_A4 = 'SIZE_A4';
    public const int MAX_BATCH = 100;

    public function __construct(
        private readonly EcomClient $ecom,
        private readonly ShipmentRows $rows,
        private readonly Settings $settings,
        private readonly AuthFlags $auth,
    ) {
    }

    public function defaultSize(): string
    {
        return ShipmentConfig::fromSettings($this->settings->get(...))->labelSize;
    }

    /**
     * @throws ShipmentIssue замовлення без створеного відправлення
     * @throws UkrposhtaApiException відмова Укрпошти чи відповідь, що не є PDF
     */
    public function one(int $orderId, string $size): string
    {
        $row = $this->createdRow($orderId) ?? throw new ShipmentIssue('sviat__ukrposhta__label_err_no_shipment');

        return $this->fetch(fn(): string => $this->ecom->pdf("shipments/{$row->barcode}/sticker", $this->query($size, $this->hidesPrice($row))));
    }

    /**
     * Замовлення без створеного відправлення пропускаються: пакетний друк не має падати через одне з них.
     * hideDeliveryPrice задається на весь запит, тож відправлення з різним платником друкуються двома
     * запитами. Злити їх в один PDF без бібліотеки не можна, тому повертається по одному PDF на групу.
     *
     * @param list<int> $orderIds
     * @return list<string> один PDF, або два, коли платники різні
     * @throws ShipmentIssue жодного відправлення для друку чи замовлень більше за MAX_BATCH
     * @throws UkrposhtaApiException відмова Укрпошти чи відповідь, що не є PDF
     */
    public function many(array $orderIds, string $size): array
    {
        if (count($orderIds) > self::MAX_BATCH) {
            throw new ShipmentIssue('sviat__ukrposhta__label_err_too_many');
        }
        $groups = [];
        foreach ($orderIds as $orderId) {
            $row = $this->createdRow($orderId);
            if ($row !== null) {
                $groups[$this->hidesPrice($row) ? 1 : 0][(string)$row->barcode] = new \stdClass();
            }
        }
        if ($groups === []) {
            throw new ShipmentIssue('sviat__ukrposhta__label_err_no_shipment');
        }
        ksort($groups);

        $pdfs = [];
        foreach ($groups as $hide => $body) {
            $pdfs[] = $this->fetch(fn(): string => $this->ecom->pdfPost('shipments/stickers-by-barcodes', $this->query($size, (bool)$hide), $body));
        }

        return $pdfs;
    }

    /**
     * Ярлик іде з токеном контрагента: відмова авторизації лишає позначку для налаштувань і замовлення, успіх її знімає.
     *
     * @param \Closure(): string $request
     */
    private function fetch(\Closure $request): string
    {
        try {
            $pdf = $request();
        } catch (UkrposhtaApiException $e) {
            $this->auth->record(AuthFlags::ECOM, $e);

            throw $e;
        }
        $this->auth->clear(AuthFlags::ECOM);

        return $pdf;
    }

    private function createdRow(int $orderId): ?object
    {
        $row = $this->rows->forOrder($orderId);

        return $row === null || PendingShipment::is($row) || (string)$row->barcode === '' ? null : $row;
    }

    /** Платник невідомий (прив'язане вручну відправлення без відповіді): ціну не ховаємо. */
    private function hidesPrice(object $row): bool
    {
        return isset($row->paid_by_recipient) && !(bool)$row->paid_by_recipient;
    }

    /** @return array<string, scalar> */
    private function query(string $size, bool $hidePrice): array
    {
        $query = ['size' => $size === self::SIZE_A4 ? self::SIZE_A4 : self::SIZE_10X10];
        // Платник — відправник: одержувачу немає чого доплачувати, тож ціна доставки на ярлику зайва.
        if ($hidePrice) {
            $query['hideDeliveryPrice'] = 1;
        }

        return $query;
    }
}
