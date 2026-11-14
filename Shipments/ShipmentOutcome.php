<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Відповідь на створення, пошук, прив'язку чи видалення від менеджера. */
final readonly class ShipmentOutcome
{
    /**
     * @param list<array{field: ?string, text: string, advice: ?string, details: ?string}> $issues
     * @param list<string> $notices мовні ключі того, що менеджеру варто знати
     * @param ?string $value показується поруч із відмовою (штрихкод, id замовлення)
     */
    public function __construct(
        public bool $ok,
        public ?string $barcode = null,
        public array $issues = [],
        public array $notices = [],
        public ?string $value = null,
    ) {
    }

    /** @param list<array{field: ?string, text: string, advice: ?string, details: ?string}> $issues */
    public static function failed(array $issues, ?string $value = null): self
    {
        return new self(false, null, $issues, [], $value);
    }

    public static function refused(string $key, ?string $value = null): self
    {
        return self::failed([ShipmentIssues::explain($key)], $value);
    }
}
