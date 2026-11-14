<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

use Okay\Core\Settings;

/** Читає налаштування при кожному зверненні: менеджер міг змінити ключі посеред запиту, а контейнер створює сервіси один раз. */
class CredentialsProvider
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function get(): Credentials
    {
        return Credentials::fromSettings($this->settings->get(...));
    }
}
