<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

use Neocode\ApsConnect\Exceptions\Concerns\CarriesRequestContext;

class RegistraRequestException extends ApsConnectException
{
    use CarriesRequestContext;
}
