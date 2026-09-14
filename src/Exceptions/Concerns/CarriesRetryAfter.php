<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions\Concerns;

/**
 * Shared by RegistraUnavailableException and AppStationUnavailableException.
 */
trait CarriesRetryAfter
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        int $status,
        array $body,
        public readonly ?int $retryAfter,
    ) {
        parent::__construct($message, $status, $body);
    }
}
