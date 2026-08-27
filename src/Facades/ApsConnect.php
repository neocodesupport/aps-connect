<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Neocode\ApsConnect\ApsConnect
 */
class ApsConnect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Neocode\ApsConnect\ApsConnect::class;
    }
}
