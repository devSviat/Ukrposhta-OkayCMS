<?php

namespace Okay\Modules\Sviat\Ukrposhta\KeyCheck;

final readonly class KeyCheckResult
{
    public function __construct(
        public KeyStatus $ecom,
        public KeyStatus $token,
        public KeyStatus $tracking,
        public \DateTimeImmutable $checkedAt,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'ecom' => $this->ecom->value,
            'token' => $this->token->value,
            'tracking' => $this->tracking->value,
            'at' => $this->checkedAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['at'] ?? null)) {
            return null;
        }
        $ecom = KeyStatus::tryFrom((string)($data['ecom'] ?? ''));
        $token = KeyStatus::tryFrom((string)($data['token'] ?? ''));
        $tracking = KeyStatus::tryFrom((string)($data['tracking'] ?? ''));

        return $ecom && $token && $tracking ? new self($ecom, $token, $tracking, new \DateTimeImmutable('@' . $data['at'])) : null;
    }
}
