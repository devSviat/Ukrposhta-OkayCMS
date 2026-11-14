<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Psr\Log\AbstractLogger;

/** Записує рядки логу, щоб тест міг перевірити, що потрапило в лог і чого там немає. */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string)$message];
    }

    public function text(): string
    {
        return implode("\n", array_column($this->records, 'message'));
    }
}
