<?php

namespace Okay\Modules\Sviat\Ukrposhta\Orders;

use Okay\Modules\Sviat\Ukrposhta\Extenders\DeliveryFilterExtender;

/**
 * Модуль Sviat/DeliveryFilter: один select «Статус доставки» й один пошук за номером для всіх перевізників.
 * Без хаба фільтра за статусом Укрпошти й пошуку штрихкодом у списку замовлень немає.
 */
final class DeliveryFilterHub
{
    /** ::class не завантажує клас, тож константа безпечна й тоді, коли хаба немає серед файлів. */
    public const string HELPER = \Okay\Modules\Sviat\DeliveryFilter\Helpers\DeliveryFilterHelper::class;

    /**
     * Ланки для Init. Реєстрація ланки на неіснуючий клас чи метод кидає виняток в init() і кладе весь сайт,
     * тож без файлів хаба чи в старішому хабі без потрібного методу ланок немає.
     *
     * @return list<array{array{string, string}, array{string, string}}>
     */
    public static function hooks(string $helper): array
    {
        $hooks = [
            [[$helper, 'findDeliveryStatusFilters'], [DeliveryFilterExtender::class, 'deliveryStatusFilters']],
            [[$helper, 'filterByDeliveryStatus'], [DeliveryFilterExtender::class, 'filterByDeliveryStatus']],
            [[$helper, 'findOrderIdsByShipmentNumber'], [DeliveryFilterExtender::class, 'ordersByShipmentNumber']],
        ];
        // Старіший хаб без якоїсь ланки: краще жодної, ніж частина фільтра.
        foreach ($hooks as [[$class, $method]]) {
            if (!class_exists($class) || !method_exists($class, $method)) {
                return [];
            }
        }

        return $hooks;
    }
}
