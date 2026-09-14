<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

use Neocode\ApsConnect\Exceptions\Concerns\CarriesRequestContext;

class AppStationRequestException extends ApsConnectException
{
    use CarriesRequestContext;
}
