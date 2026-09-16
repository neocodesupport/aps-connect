<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

use Neocode\ApsConnect\Exceptions\Concerns\CarriesRetryAfter;

final class PublisherUnavailableException extends PublisherRequestException
{
    use CarriesRetryAfter;
}
