<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

final class AppStationUnavailableException extends AppStationRequestException
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
