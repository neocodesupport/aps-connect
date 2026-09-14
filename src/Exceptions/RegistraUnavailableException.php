<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

use Neocode\ApsConnect\Exceptions\Concerns\CarriesRetryAfter;

final class RegistraUnavailableException extends RegistraRequestException
{
    use CarriesRetryAfter;
}
