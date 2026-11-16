<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;

/**
 * Створює, знаходить, прив'язує й видаляє відправлення замовлення. POST shipments надсилається рівно
 * один раз: після обриву Укрпошта могла вже створити відправлення.
 */
final class ShipmentService
{
    /** Укрпошта видалила чи скасувала: такий штрихкод нічого не відправить. */
    private const array GONE = ['DELETED', 'CANCELED'];

    private readonly \Closure $now;

    /** @param ?\Closure(): int $now */
    public function __construct(
        private readonly EcomClient $ecom,
        private readonly ShipmentRows $rows,
        private readonly SenderClient $sender,
        private readonly RecipientParty $recipients,
        private readonly AuthFlags $auth,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    public function create(int $orderId, ShipmentDraft $draft): ShipmentOutcome
    {
        $errors = $draft->errors();
        if ($errors !== []) {
            return ShipmentOutcome::failed(array_map(
                static fn(string $field, string $key): array => ['field' => $field] + ShipmentIssues::explain($key),
                array_keys($errors),
                $errors,
            ));
        }
        $occupied = $this->occupied($this->rows->forOrder($orderId));
        if ($occupied !== null) {
            return $occupied;
        }
        if (!$this->rows->reserve($orderId)) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_pending');
        }

        try {
            $senderUuid = $this->sender->uuid();
            $recipient = $this->recipients->create($draft->name, UpPhone::normalize($draft->phone) ?? $draft->phone, $draft->officePostcode);
            $this->rows->rememberSender($orderId, $senderUuid);
        } catch (ShipmentIssue $e) {
            $this->rows->release($orderId);

            return ShipmentOutcome::refused($e->getMessage());
        } catch (UkrposhtaApiException $e) {
            $this->auth->record(AuthFlags::ECOM, $e);
            // Сторони відправлення ще не відправлення: повтор нічого не подвоїть.
            $this->rows->release($orderId);

            return ShipmentOutcome::failed([ShipmentIssues::explain($e)]);
        } catch (\Throwable $e) {
            $this->rows->release($orderId);

            throw $e;
        }

        try {
            $answer = $this->call('POST', 'shipments', $draft->toRequest($senderUuid, $recipient['client_uuid'], $recipient['address_id'], $orderId));
        } catch (UkrposhtaApiException $e) {
            if (self::mayExist($e)) {
                $this->rows->markUncertain($orderId);

                return ShipmentOutcome::failed([['details' => $e->apiMessage] + ShipmentIssues::explain('sviat__ukrposhta__ship_err_uncertain')]);
            }
            // 4xx чи тіло, що не кодується (запит не пішов): нічого не створено.
            $this->rows->release($orderId);

            return ShipmentOutcome::failed([ShipmentIssues::explain($e)]);
        } catch (\Throwable $e) {
            // Невідомо, чи запит устиг піти: замовлення чекає на пошук, а не на повтор.
            $this->rows->markUncertain($orderId);

            throw $e;
        }

        $shipment = self::shipment($answer + ['lifecycle' => ['status' => 'CREATED']], $draft->cod ? $draft->codAmount : null, $draft->paidByRecipient);
        if ($shipment === null) {
            $this->rows->markUncertain($orderId);

            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_uncertain');
        }
        // Що надіслали, а не що повернули: відповідь створення повного відправлення не обіцяє.
        $shipment['details'] = ShipmentDetails::encode(ShipmentDetails::fromDraft($draft, $answer));
        if ($this->rows->complete($orderId, $shipment)) {
            return new ShipmentOutcome(true, $shipment['barcode']);
        }
        // Відправлення в Укрпошті є, а запис не вдався: звільнене замовлення дало б створити друге.
        $this->rows->markUncertain($orderId);

        return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_not_saved', $shipment['barcode']);
    }

    /**
     * Шукає відправлення невідомого створення серед відправлень нашого відправника за externalId.
     * Знайдено — воно стає відправленням замовлення; ні — рядок лишається невідомим, менеджер прив'язує штрихкод.
     *
     * @param ?bool $paidByRecipient платник із чернетки замовлення, коли Укрпошта його не повернула
     */
    public function lookup(int $orderId, ?bool $paidByRecipient = null): ShipmentOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if (!PendingShipment::needsCheck($row, ($this->now)())) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_nothing_to_check');
        }
        $ours = $this->ourSenders($row);
        $rowSender = self::rowSender($row);
        // Без sender_uuid запит POST shipments не почався (його пишуть перед надсиланням): досить поточного відправника.
        $senders = $rowSender !== null ? [$rowSender] : array_slice($ours, 0, 1);

        foreach ($senders as $senderUuid) {
            try {
                // Список відправлень за senderUuid без ключа не перевірено: форма відповіді (масив чи сторінка) невідома.
                $answer = $this->call('GET', 'shipments?senderUuid=' . rawurlencode($senderUuid));
            } catch (UkrposhtaApiException $e) {
                if ($e->kind !== ApiErrorKind::NotFound) {
                    return ShipmentOutcome::failed([ShipmentIssues::explain($e)]);
                }
                continue;
            }
            // Повтор після обриву міг створити кілька відправлень замовлення. Дати створення в нотатках API немає,
            // а відв'язані штрихкоди не зберігаються, тож береться останнє в списку як найновіше.
            foreach (array_reverse(self::items($answer)) as $item) {
                if (!is_array($item)
                    || (string)($item['externalId'] ?? '') !== (string)$orderId
                    || !in_array((string)($item['sender']['uuid'] ?? ''), $ours, true)
                    || in_array(self::status($item), self::GONE, true)) {
                    continue;
                }
                $shipment = self::shipment($item, null, $paidByRecipient);
                if ($shipment === null) {
                    continue;
                }
                if (!$this->rows->complete($orderId, $shipment)) {
                    return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_not_saved', $shipment['barcode']);
                }

                return new ShipmentOutcome(true, $shipment['barcode'], [], ['sviat__ukrposhta__ship_lookup_found']);
            }
        }

        return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_lookup_none');
    }

    public function attach(int $orderId, string $input): ShipmentOutcome
    {
        $barcode = Barcode::parse($input);
        if ($barcode === null) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_barcode');
        }
        $row = $this->rows->forOrder($orderId);
        if (PendingShipment::running($row, ($this->now)())) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_pending');
        }
        if ($row !== null && !PendingShipment::is($row)) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_already', (string)$row->barcode);
        }
        $owner = $this->rows->orderOfBarcode($barcode);
        if ($owner !== null) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_barcode_elsewhere', (string)$owner);
        }

        try {
            $answer = $this->call('GET', 'shipments/barcode/' . rawurlencode($barcode));
        } catch (UkrposhtaApiException $e) {
            return $e->kind === ApiErrorKind::NotFound
                ? ShipmentOutcome::refused('sviat__ukrposhta__ship_err_barcode_not_found')
                : ShipmentOutcome::failed([ShipmentIssues::explain($e)]);
        }
        // Чужий штрихкод дав би менеджеру друкувати ярлик і видаляти відправлення іншого відправника.
        if (!in_array((string)($answer['sender']['uuid'] ?? ''), $this->ourSenders($row), true)) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_foreign_sender');
        }
        $externalId = (string)($answer['externalId'] ?? '');
        if ($externalId !== '' && $externalId !== (string)$orderId) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_other_order', $externalId);
        }
        if (in_array(self::status($answer), self::GONE, true)) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_barcode_gone');
        }
        $shipment = self::shipment($answer, null);
        if ($shipment === null || $shipment['barcode'] !== $barcode) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_api_malformed');
        }

        $saved = PendingShipment::is($row) ? $this->rows->complete($orderId, $shipment) : $this->rows->add($orderId, $shipment);
        if (!$saved) {
            // Штрихкод унікальний: тим часом інший запит міг прив'язати його до іншого замовлення.
            $owner = $this->rows->orderOfBarcode($barcode);

            return $owner !== null && $owner !== $orderId
                ? ShipmentOutcome::refused('sviat__ukrposhta__ship_err_barcode_elsewhere', (string)$owner)
                : ShipmentOutcome::refused('sviat__ukrposhta__ship_err_pending');
        }

        return new ShipmentOutcome(true, $barcode);
    }

    /**
     * Укрпошта видаляє лише ще не зареєстроване відправлення. Lifecycle перестають опитувати з першою подією
     * трекінгу, тож `status` лишається CREATED і на посилці в дорозі: вирішує ще й група з трекінгу.
     */
    public static function deletable(object $row): bool
    {
        return in_array($row->status ?? null, [null, 'CREATED'], true)
            && in_array($row->status_group ?? null, [null, 'created'], true);
    }

    public function remove(int $orderId): ShipmentOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null || PendingShipment::is($row) || (string)($row->uuid ?? '') === '') {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_none');
        }
        if (!self::deletable($row)) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_not_deletable');
        }
        try {
            $this->call('DELETE', 'shipments/' . rawurlencode((string)$row->uuid));
        } catch (UkrposhtaApiException $e) {
            return ShipmentOutcome::failed([ShipmentIssues::explain($e)]);
        }
        $this->rows->remove($orderId);

        return new ShipmentOutcome(true, (string)$row->barcode);
    }

    /**
     * Забуває відправлення замовлення, не питаючи Укрпошту: зареєстроване (його не видалити) або невідоме
     * створення, якого менеджер не знайшов. В Укрпошті воно лишається.
     */
    public function unlink(int $orderId): ShipmentOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_none');
        }
        if (PendingShipment::running($row, ($this->now)())) {
            return ShipmentOutcome::refused('sviat__ukrposhta__ship_err_pending');
        }
        $this->rows->remove($orderId);

        return new ShipmentOutcome(true, is_string($row->barcode ?? null) ? $row->barcode : null);
    }

    /**
     * Виклик eCom з токеном: відмова авторизації лишає позначку для налаштувань і замовлення, успіх її знімає.
     *
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        try {
            $answer = $this->ecom->call($method, $path, $body);
        } catch (UkrposhtaApiException $e) {
            $this->auth->record(AuthFlags::ECOM, $e);

            throw $e;
        }
        $this->auth->clear(AuthFlags::ECOM);

        return $answer;
    }

    /**
     * Відправники, чиї відправлення — наші: усі клієнти магазину зі сховища й той, від якого пішов запит
     * цього рядка. Читання не створює клієнта, як SenderClient::uuid().
     *
     * @return list<string>
     */
    private function ourSenders(?object $row): array
    {
        return array_values(array_unique(array_filter([self::rowSender($row), ...$this->sender->known()], is_string(...))));
    }

    private static function rowSender(?object $row): ?string
    {
        $uuid = $row->sender_uuid ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** Відмова, коли замовлення вже має рядок; null — вільне. */
    private function occupied(?object $row): ?ShipmentOutcome
    {
        return match (true) {
            $row === null => null,
            PendingShipment::needsCheck($row, ($this->now)()) => ShipmentOutcome::refused('sviat__ukrposhta__ship_err_uncertain'),
            PendingShipment::is($row) => ShipmentOutcome::refused('sviat__ukrposhta__ship_err_pending'),
            default => ShipmentOutcome::refused('sviat__ukrposhta__ship_err_already', (string)$row->barcode),
        };
    }

    /** Після цих запит міг дійти, і відправлення могло з'явитися: обрив, 5xx, 2xx з незрозумілим тілом. */
    private static function mayExist(UkrposhtaApiException $e): bool
    {
        return in_array($e->kind, [ApiErrorKind::Unreachable, ApiErrorKind::ServerError, ApiErrorKind::Malformed, ApiErrorKind::Empty], true);
    }

    /**
     * @param array<mixed> $answer
     * @return list<mixed>
     */
    private static function items(array $answer): array
    {
        if (array_is_list($answer)) {
            return $answer;
        }
        foreach (['content', 'shipments', 'items', 'data'] as $key) {
            if (is_array($answer[$key] ?? null) && array_is_list($answer[$key])) {
                return $answer[$key];
            }
        }

        return [];
    }

    /** @param array<mixed> $item */
    private static function status(array $item): ?string
    {
        $status = $item['lifecycle']['status'] ?? $item['status'] ?? null;

        return is_string($status) && $status !== '' ? mb_substr($status, 0, 32) : null;
    }

    /**
     * Відправлення з відповіді Укрпошти; null, коли в ній немає uuid чи штрихкоду.
     *
     * @param array<mixed> $item
     * @return ?array{uuid: string, barcode: string, status: ?string, delivery_price: ?float, cod_amount: ?float, cod_fee: ?float, paid_by_recipient: ?bool, details: ?string}
     */
    private static function shipment(array $item, ?float $codAmount, ?bool $paidByRecipient = null): ?array
    {
        $uuid = $item['uuid'] ?? null;
        $barcode = is_string($item['barcode'] ?? null) ? Barcode::parse($item['barcode']) : null;
        if (!is_string($uuid) || $uuid === '' || strlen($uuid) > 36 || $barcode === null) {
            return null;
        }
        $postPay = self::money($item['postPay'] ?? null);

        return [
            'uuid' => $uuid,
            'barcode' => $barcode,
            'status' => self::status($item),
            'delivery_price' => self::money($item['deliveryPrice'] ?? null),
            'cod_amount' => $postPay !== null && $postPay > 0 ? $postPay : $codAmount,
            'cod_fee' => self::money($item['postPayDeliveryPrice'] ?? null),
            // Хто платить за доставку, видно з відповіді Укрпошти; інакше з чернетки замовлення.
            'paid_by_recipient' => is_bool($item['paidByRecipient'] ?? null) ? $item['paidByRecipient'] : $paidByRecipient,
            'details' => ShipmentDetails::encode(ShipmentDetails::fromApi($item)),
        ];
    }

    private static function money(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? round((float)$value, 2) : null;
    }
}
