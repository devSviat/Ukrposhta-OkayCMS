<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\CredentialsProvider;

final class FixedCredentials extends CredentialsProvider
{
    public function __construct(private readonly Credentials $credentials)
    {
    }

    public function get(): Credentials
    {
        return $this->credentials;
    }
}
