<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend;

use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyStatus;

/** Дані шаблону для блоку ключів; з ключа виходить лише те, чи він заданий, жодного його символу. */
final class KeySettingsView
{
    /**
     * @return array{fields: list<array{name:string, set:bool, status:string}>, any_set:bool, sandbox:bool, checked_at:?string, has_problem:bool}
     */
    public static function vars(Credentials $credentials, ?KeyCheckResult $result, ?\DateTimeZone $timezone = null): array
    {
        $values = [
            'ecom_bearer' => $credentials->ecomBearer,
            'counterparty_token' => $credentials->counterpartyToken,
            'tracking_bearer' => $credentials->trackingBearer,
        ];
        $statuses = [
            'ecom_bearer' => $result?->ecom,
            'counterparty_token' => $result?->token,
            'tracking_bearer' => $result?->tracking,
        ];
        $fields = [];
        foreach (array_keys(KeySettings::FIELDS) as $name) {
            $fields[] = [
                'name' => $name,
                'set' => $values[$name] !== '',
                // Не заданий ключ не «не перевірений»: перевіряти просто нічого.
                'status' => $values[$name] === '' ? 'missing' : ($statuses[$name] ?? KeyStatus::Unchecked)->value,
            ];
        }

        return [
            'fields' => $fields,
            'any_set' => in_array(true, array_column($fields, 'set'), true),
            'sandbox' => $credentials->sandbox,
            'checked_at' => $result?->checkedAt
                ->setTimezone($timezone ?? new \DateTimeZone(date_default_timezone_get()))
                ->format('d.m.Y H:i'),
            'has_problem' => $result !== null && array_filter(
                $statuses,
                static fn(?KeyStatus $status): bool => in_array($status, [KeyStatus::BadBearer, KeyStatus::BadToken, KeyStatus::Unreachable], true),
            ) !== [],
        ];
    }
}
