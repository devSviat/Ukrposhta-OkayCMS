<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

/**
 * Повідомлення й стек потрапляють у логи. Текст сюди приходить уже замаскованим (див. mask()), а стек
 * зберігається без аргументів викликів: з zend.exception_ignore_args=Off у них лежали б запит із токеном
 * в URL, заголовок Authorization і масив секретів.
 */
final class UkrposhtaApiException extends \RuntimeException
{
    private function __construct(
        public readonly ApiErrorKind $kind,
        string $message,
        public readonly ?string $apiMessage = null,
    ) {
        parent::__construct($message);
        new \ReflectionProperty(\Exception::class, 'trace')->setValue(
            $this,
            array_map(static fn(array $frame): array => array_diff_key($frame, ['args' => true]), $this->getTrace()),
        );
    }

    /** Тексти вже пропущені через mask(): секрети сюди не передаються, бо аргументи потрапили б у стек. */
    public static function of(ApiErrorKind $kind, string $message, ?string $apiMessage = null): self
    {
        return new self($kind, $message, $apiMessage);
    }

    /** Рядок для логу: клас і замаскований текст; так пишуть і винятки не від API, бо в них може бути URL з токеном. */
    public static function describe(\Throwable $e): string
    {
        return $e::class . ': ' . self::mask($e->getMessage());
    }

    /**
     * Єдине місце маскування: параметр token= в URL і самі значення секретів, також закодовані для URL —
     * так Укрпошта може повторити їх у тексті помилки.
     *
     * @param list<string> $secrets
     */
    public static function mask(string $text, array $secrets = []): string
    {
        $text = preg_replace('/([?&]token=)[^&\s"\']*/i', '$1***', $text) ?? '***';
        $forms = [];
        foreach ($secrets as $secret) {
            array_push($forms, $secret, rawurlencode($secret), urlencode($secret));
        }
        $replace = array_fill_keys(array_filter($forms, static fn(string $s): bool => $s !== ''), '***');

        return $replace === [] ? $text : strtr($text, $replace);
    }
}
