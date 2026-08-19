<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ApsConnect\ApsConnect\ApsConnect
 */
class ApsConnect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ApsConnect\ApsConnect\ApsConnect::class;
    }
}
