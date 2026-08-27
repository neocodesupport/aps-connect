<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Exceptions;

class AppStationRequestException extends ApsConnectException
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
