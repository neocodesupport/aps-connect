<?php

declare(strict_types=1);

namespace ApsConnect\ApsConnect\Tests;

use ApsConnect\ApsConnect\ApsConnectServiceProvider;
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
