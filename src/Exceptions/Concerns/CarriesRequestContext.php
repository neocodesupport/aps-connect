<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions\Concerns;

/**
 * Shared by RegistraRequestException and AppStationRequestException, which
 * carry identical fields but must stay separate classes so callers can catch
 * "any Registra failure" vs "any App Station failure" independently.
 */
trait CarriesRequestContext
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }
}
