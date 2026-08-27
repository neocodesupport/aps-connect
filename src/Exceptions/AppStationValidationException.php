<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Exceptions;

final class AppStationValidationException extends AppStationRequestException
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        int $status,
        array $body,
        public readonly array $errors,
    ) {
        parent::__construct($message, $status, $body);
    }
}
