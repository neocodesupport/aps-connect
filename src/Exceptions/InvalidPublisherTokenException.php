<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

/**
 * The publisher session token (`--token`/APS_TOKEN) was rejected or expired
 * — thrown on a 401 from a `publisher/...` endpoint. Remediation is
 * `aps login` again, unlike InvalidAppStationApiKeyException's remediation
 * (check appstation.conf.local.json).
 */
final class InvalidPublisherTokenException extends PublisherRequestException {}
