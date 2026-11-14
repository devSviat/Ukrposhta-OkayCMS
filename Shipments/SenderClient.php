<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/** Клієнт-відправник в Укрпошті: створюється раз і переживає запити, доки реквізити відправника не змінилися. */
final class SenderClient
{
    use CreatesParties;

    public const string STATE = 'sender_client';
    public const string KNOWN = 'sender_clients_known';
    /** Відправлення живуть до кількох місяців; стільки змін реквізитів за цей час із запасом. */
    private const int KNOWN_LIMIT = 20;

    public function __construct(
        EcomClient $ecom,
        private readonly StateStorage $state,
        private readonly SenderSettings $settings,
    ) {
        $this->ecom = $ecom;
    }

    public function uuid(): string
    {
        $sender = $this->settings->read();
        $hash = self::hash($sender);

        $cached = json_decode($this->state->get(self::STATE) ?? '', true);
        if (is_array($cached) && ($cached['hash'] ?? null) === $hash && is_string($cached['uuid'] ?? null) && $cached['uuid'] !== '') {
            return $cached['uuid'];
        }

        $phone = UpPhone::normalize($sender['phone']) ?? throw new ShipmentIssue('sviat__ukrposhta__ship_err_sender_phone');

        // Два одночасні перші відправлення можуть створити по клієнту: обидва придатні, кешується останній.
        $addressId = $this->createAddress($sender['postcode']);
        $uuid = $this->createClient(self::clientBody($sender, $addressId, $phone));
        $this->state->set(self::STATE, json_encode(['uuid' => $uuid, 'address_id' => $addressId, 'hash' => $hash], JSON_THROW_ON_ERROR));
        $known = array_values(array_diff($this->known(), [$uuid]));
        $this->state->set(self::KNOWN, json_encode(array_slice([$uuid, ...$known], 0, self::KNOWN_LIMIT), JSON_THROW_ON_ERROR));

        return $uuid;
    }

    /**
     * Клієнти-відправники, яких магазин створював, новіші першими; лише читає сховище.
     * Відправлення, створене до зміни реквізитів, належить старому клієнту й лишається нашим.
     *
     * @return list<string>
     */
    public function known(): array
    {
        $known = json_decode($this->state->get(self::KNOWN) ?? '', true);
        $cached = json_decode($this->state->get(self::STATE) ?? '', true)['uuid'] ?? null;
        $uuids = [...(is_array($known) ? $known : []), $cached];

        return array_values(array_unique(array_filter($uuids, static fn(mixed $u): bool => is_string($u) && $u !== '')));
    }

    /**
     * Лише поля обраного типу: перемикач типу лишає в налаштуваннях і другий набір, його зміна клієнта не міняє.
     *
     * @param array<string, string> $sender
     * @return array<string, string>
     */
    private static function identity(array $sender): array
    {
        $fields = $sender['type'] === SenderSettings::PRIVATE_ENTREPRENEUR
            ? ['lastName' => $sender['last_name'], 'firstName' => $sender['first_name'], 'tin' => $sender['tin']]
            : ['name' => $sender['name'], 'edrpou' => $sender['edrpou']];

        return ['type' => $sender['type']] + $fields;
    }

    /** @param array<string, string> $sender */
    private static function hash(array $sender): string
    {
        return hash('sha256', json_encode(
            [self::identity($sender), $sender['phone'], $sender['iban'], $sender['postcode']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<string, string> $sender
     * @return array<string, mixed>
     */
    private static function clientBody(array $sender, int $addressId, string $phone): array
    {
        return self::identity($sender) + [
            'addressId' => $addressId,
            'phoneNumber' => $phone,
            'bankAccount' => $sender['iban'],
        ];
    }
}
