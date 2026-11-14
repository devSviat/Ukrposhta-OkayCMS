<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Storage\Mutex;
use Psr\Log\LoggerInterface;

/**
 * Статуси відправлень. Укрпошта не має вебхуків, тож планувальник опитує трекінг пакетами; відправлення,
 * яке ще не зареєстроване у відділенні, трекінг не бачить, тому його статус береться з lifecycle eCom.
 */
final class ShipmentTracking
{
    private const string MUTEX = 'track';
    private const int BATCHES_PER_TICK = 10;
    private const int LIFECYCLE_PER_TICK = 50;
    private const int RECHECK_SECONDS = 900;
    private const int LIFECYCLE_RECHECK_SECONDS = 3600;
    /** Довжини колонок: довше значення провалило б UPDATE, а збій SQL ядро лише пише в лог. */
    private const int EVENT_NAME_LENGTH = 255;
    private const int STATUS_LENGTH = 32;

    /** Посилка, що все ще в русі через два місяці, застрягла; повторні запити лише гальмують чергу. */
    public const int TRACK_FOR_DAYS = 60;

    private readonly \Closure $now;

    /** @param ?\Closure(): int $now */
    public function __construct(
        private readonly TrackingClient $tracking,
        private readonly EcomClient $ecom,
        private readonly ShipmentRows $rows,
        private readonly Mutex $mutex,
        private readonly AuthFlags $auth,
        private readonly LoggerInterface $logger,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    /**
     * Планувальник: один тік не чекає на інший. Помилка авторизації зупиняє тік і лишає прапорець для налаштувань;
     * інший збій трекінгу не заважає опитати lifecycle, бо той іде іншим API.
     */
    public function tick(): void
    {
        if (!$this->mutex->acquire(self::MUTEX)) {
            return;
        }
        try {
            if (!$this->tracking->configured()) {
                $this->auth->clear(AuthFlags::TRACKING);
            } elseif (!$this->guarded(AuthFlags::TRACKING, $this->pollTracking(...))) {
                return;
            }
            if ($this->ecom->configured()) {
                $this->guarded(AuthFlags::ECOM, $this->pollLifecycle(...));
            }
        } finally {
            $this->mutex->release(self::MUTEX);
        }
    }

    /** @return bool false, коли тік треба зупинити через авторизацію */
    private function guarded(string $api, \Closure $poll): bool
    {
        try {
            $poll();
        } catch (UkrposhtaApiException $e) {
            if ($this->auth->record($api, $e)) {
                return false;
            }
            $this->logger->warning('Ukrposhta ' . $api . ' polling stopped: ' . UkrposhtaApiException::describe($e));
        }

        return true;
    }

    /** Кнопка в адмінці: одне відправлення зараз, з повною історією. */
    public function refresh(int $orderId): ShipmentOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null || PendingShipment::is($row) || (string)($row->barcode ?? '') === '') {
            return ShipmentOutcome::refused('sviat__ukrposhta__track_err_no_shipment');
        }
        if (!$this->tracking->configured()) {
            return ShipmentOutcome::refused('sviat__ukrposhta__track_err_not_configured');
        }
        try {
            $history = TrackingEvent::history($this->tracking->history((string)$row->barcode));
        } catch (UkrposhtaApiException $e) {
            // Текст про ключ eCom тут збив би з пантелику: відмовив ключ трекінгу.
            return ShipmentOutcome::failed([$this->auth->record(AuthFlags::TRACKING, $e)
                ? ['field' => null, 'text' => 'sviat__ukrposhta__track_err_bad_key', 'advice' => 'sviat__ukrposhta__ship_advice_key', 'details' => $e->apiMessage]
                : ShipmentIssues::explain($e)]);
        }
        $this->auth->clear(AuthFlags::TRACKING);
        $latest = TrackingEvent::latest($history);
        if ($latest === null) {
            $this->rows->markChecked($orderId, $this->stamp());

            return new ShipmentOutcome(true, (string)$row->barcode, [], ['sviat__ukrposhta__track_no_events']);
        }
        $this->rows->saveEvent($orderId, $this->eventValues($latest) + ['status_group' => $this->groupOf($history, $row)], $this->stamp());

        return new ShipmentOutcome(true, (string)$row->barcode);
    }

    private function pollTracking(): void
    {
        $now = ($this->now)();
        $due = $this->rows->dueForTracking(
            TrackingClient::MAX_BARCODES * self::BATCHES_PER_TICK,
            date('Y-m-d H:i:s', $now - self::TRACK_FOR_DAYS * 86400),
            date('Y-m-d H:i:s', $now - self::RECHECK_SECONDS),
        );
        foreach (array_chunk($due, TrackingClient::MAX_BARCODES) as $batch) {
            $orderOf = [];
            foreach ($batch as $row) {
                $orderOf[(string)$row->barcode] = (int)$row->order_id;
            }
            try {
                $answer = $this->tracking->lastStatuses(array_keys($orderOf));
            } catch (UkrposhtaApiException $e) {
                if (!self::onlyThisRequest($e)) {
                    throw $e;
                }
                // Пакет іде в кінець черги: інакше він щотіку падав би першим і решта відправлень не оновлювалася б.
                $this->logger->warning('Ukrposhta tracking batch failed: ' . UkrposhtaApiException::describe($e));
                foreach ($batch as $row) {
                    $this->rows->markChecked((int)$row->order_id, $this->stamp());
                }
                continue;
            }
            $this->auth->clear(AuthFlags::TRACKING);

            $latest = [];
            foreach (TrackingEvent::history(array_values(array_filter($answer, is_array(...)))) as $event) {
                $latest[$event->barcode] = $event;
            }
            foreach ($batch as $row) {
                $event = $latest[(string)$row->barcode] ?? null;
                if ($event === null) {
                    $this->rows->markChecked((int)$row->order_id, $this->stamp());
                    continue;
                }
                $this->rows->saveEvent((int)$row->order_id, $this->eventValues($event), $this->stamp());
            }
        }
    }

    private function pollLifecycle(): void
    {
        $now = ($this->now)();
        $due = $this->rows->dueForLifecycle(
            self::LIFECYCLE_PER_TICK,
            date('Y-m-d H:i:s', $now - self::TRACK_FOR_DAYS * 86400),
            date('Y-m-d H:i:s', $now - self::LIFECYCLE_RECHECK_SECONDS),
        );
        foreach ($due as $row) {
            try {
                $answer = $this->ecom->call('GET', 'shipments/' . rawurlencode((string)$row->uuid) . '/lifecycle');
            } catch (UkrposhtaApiException $e) {
                if (!self::onlyThisRequest($e)) {
                    throw $e;
                }
                // NotFound буває штатно (відправлення видалили в кабінеті), тож у лог іде лише інша відмова.
                if ($e->kind !== ApiErrorKind::NotFound) {
                    $this->logger->warning('Ukrposhta lifecycle of order ' . (int)$row->order_id . ' failed: ' . UkrposhtaApiException::describe($e));
                }
                // Рядок не вилучається, а позначається перевіреним: інакше він щотіку стояв би першим і тримав решту.
                $this->rows->saveLifecycle((int)$row->order_id, (string)($row->status ?? 'CREATED'), $this->stamp());
                continue;
            }
            $this->auth->clear(AuthFlags::ECOM);
            $this->rows->saveLifecycle((int)$row->order_id, self::lifecycleStatus($answer) ?? (string)($row->status ?? 'CREATED'), $this->stamp());
        }
    }

    /**
     * Відмова саме цього запиту: повтор дасть те саме, тож рядки йдуть у кінець черги. Недоступна Укрпошта (обрив, 5xx)
     * і відмова авторизації зупиняють опитування цього API в тіку: інші запити впали б так само.
     */
    private static function onlyThisRequest(UkrposhtaApiException $e): bool
    {
        return in_array($e->kind, [ApiErrorKind::Rejected, ApiErrorKind::Malformed, ApiErrorKind::Empty, ApiErrorKind::NotFound], true);
    }

    /**
     * Відповідь lifecycle — об'єкт зі `status` або список змін статусу, де поточний останній.
     *
     * @param array<mixed> $answer
     */
    private static function lifecycleStatus(array $answer): ?string
    {
        $entry = array_is_list($answer) ? ($answer === [] ? [] : $answer[array_key_last($answer)]) : $answer;
        $status = is_array($entry) ? ($entry['status'] ?? null) : null;

        return is_string($status) && $status !== '' ? mb_substr($status, 0, self::STATUS_LENGTH) : null;
    }

    /** @param list<TrackingEvent> $history */
    private function groupOf(array $history, object $row): ?string
    {
        $latest = TrackingEvent::latest($history);
        $wasReturning = in_array(31200, array_map(static fn(TrackingEvent $e): ?int => $e->code, $history), true);

        return ShipmentStatus::group($latest?->code, $row->status ?? null, $latest?->reason, $wasReturning ? 'returning' : ($row->status_group ?? null));
    }

    /** @return array{event_code: int, event_name: string, event_at: string, event_reason: ?int} */
    private function eventValues(TrackingEvent $event): array
    {
        return ['event_code' => (int)$event->code, 'event_name' => mb_substr($event->name, 0, self::EVENT_NAME_LENGTH), 'event_at' => (string)$event->at(), 'event_reason' => $event->reason];
    }

    private function stamp(): string
    {
        return date('Y-m-d H:i:s', ($this->now)());
    }
}
