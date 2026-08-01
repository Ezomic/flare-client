<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Thijssensoftware\FlareClient\FlareClientServiceProvider;
use Thijssensoftware\RequestId\RequestIdServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RequestIdServiceProvider::class, FlareClientServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('flare-client.key', 'flr_testkeytestkeytest');
        $app['config']->set('flare-client.url', 'https://flare.test');
        $app['config']->set('cache.default', 'array');
    }
}
