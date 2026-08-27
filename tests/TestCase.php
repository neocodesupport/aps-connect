<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Tests;

use Neocode\ApsConnect\ApsConnectServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ApsConnectServiceProvider::class,
        ];
    }
}
