<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries;

use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/**
 * Як пройшов останній запуск довідників; зберігається як JSON у стані модуля.
 * `status`: ok — усі області замінено; partial — частина областей зберегла старі рядки
 * (`skipped`: назва → причина, `unresolved`: назви без id); failed — запуск зупинився (`error`);
 * no_keys — немає bearer eCom, класифікатор не викликався.
 */
final class DictionaryStatus
{
    public const string OK = 'ok';
    public const string PARTIAL = 'partial';
    public const string FAILED = 'failed';
    public const string NO_KEYS = 'no_keys';

    private const string STATE = 'dict_status';

    public ?int $requestedAt = null;
    public ?int $runningSince = null;
    /** @var array{started:int, finished:int, status:string, counts:array<string, array<string, int>>, skipped:array<string, string>, unresolved:list<string>, error:?string}|null */
    public ?array $lastRun = null;

    public static function load(StateStorage $state): self
    {
        $status = new self();
        $data = json_decode((string)$state->get(self::STATE), true);
        if (!is_array($data)) {
            return $status;
        }
        foreach (['requestedAt', 'runningSince'] as $field) {
            $status->$field = is_int($data[$field] ?? null) ? $data[$field] : null;
        }
        $status->lastRun = is_array($data['lastRun'] ?? null) ? $data['lastRun'] : null;

        return $status;
    }

    public function save(StateStorage $state): void
    {
        $state->set(self::STATE, json_encode([
            'requestedAt' => $this->requestedAt,
            'runningSince' => $this->runningSince,
            'lastRun' => $this->lastRun,
        ], JSON_UNESCAPED_UNICODE));
    }
}
