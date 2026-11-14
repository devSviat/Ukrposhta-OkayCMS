<?php

namespace Okay\Modules\Sviat\Ukrposhta\Integration;

final readonly class UpAddress
{
    public function __construct(public string $city, public string $line, public string $postcode)
    {
    }
}
