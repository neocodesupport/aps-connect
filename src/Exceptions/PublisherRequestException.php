<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

use Neocode\ApsConnect\Exceptions\Concerns\CarriesRequestContext;

/**
 * Base of a third exception hierarchy, deliberately separate from
 * RegistraRequestException and AppStationRequestException: an App Station
 * *publisher session* (`aps login` / APS_TOKEN) failure is a different
 * failure mode from a rejected Registra licence or a rejected product/
 * instance API key, and callers should be able to catch each independently.
 */
class PublisherRequestException extends ApsConnectException
{
    use CarriesRequestContext;
}
