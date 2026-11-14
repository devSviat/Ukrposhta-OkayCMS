<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Відправлення не можна почати через дані магазину чи замовлення: message — мовний ключ причини, до API не дійшло. */
final class ShipmentIssue extends \DomainException
{
}
