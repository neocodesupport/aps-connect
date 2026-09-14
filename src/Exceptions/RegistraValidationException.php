<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

use Neocode\ApsConnect\Exceptions\Concerns\CarriesValidationErrors;

final class RegistraValidationException extends RegistraRequestException
{
    use CarriesValidationErrors;
}
