<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Опис вмісту нового відправлення: товари замовлення, номер замовлення або власний текст з налаштувань. */
final class ShipmentDescription
{
    public const string PRODUCTS = 'products';
    public const string ORDER = 'order';
    public const string TEXT = 'text';
    /** Перший — типовий. */
    public const array MODES = [self::PRODUCTS, self::ORDER, self::TEXT];

    /** Поле `description` відправлення eCom: до 255 символів. */
    public const int MAX_LENGTH = 255;

    private const string SEPARATOR = '; ';
    private const string ELLIPSIS = '…';

    /** @param list<object> $purchases покупки замовлення (product_name, variant_name, amount) */
    public static function forOrder(string $mode, string $text, int $orderId, array $purchases): string
    {
        $description = match ($mode) {
            self::TEXT => self::fit(self::squash($text)),
            self::PRODUCTS => self::products($purchases),
            default => '',
        };

        return $description !== '' ? $description : self::order($orderId);
    }

    public static function order(int $orderId): string
    {
        return 'Замовлення №' . $orderId;
    }

    /**
     * Назви через «; », бо кома трапляється всередині назв («…Модель-1, ART-0001»); кількість, якщо більше одного.
     *
     * @param list<object> $purchases
     */
    public static function products(array $purchases): string
    {
        $items = [];
        foreach ($purchases as $purchase) {
            $name = self::squash((string)($purchase->product_name ?? ''));
            if ($name === '') {
                continue;
            }
            $variant = self::squash((string)($purchase->variant_name ?? ''));
            $amount = (int)($purchase->amount ?? 1);
            $items[] = $name . ($variant !== '' ? " ({$variant})" : '') . ($amount > 1 ? " ×{$amount}" : '');
        }

        return self::fit(implode(self::SEPARATOR, $items));
    }

    /** Обрізає по цілому слову з «…»; слово, довше за весь ліміт, ріжеться посередині. */
    private static function fit(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        $room = self::MAX_LENGTH - mb_strlen(self::ELLIPSIS);
        $head = mb_substr($text, 0, $room);
        $next = mb_substr($text, $room, 1);
        if (!preg_match('/^[\s;,]$/u', $next)) {
            $space = mb_strrpos($head, ' ');
            if ($space !== false) {
                $head = mb_substr($head, 0, $space);
            }
        }

        return rtrim($head, " ;,\t") . self::ELLIPSIS;
    }

    private static function squash(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
