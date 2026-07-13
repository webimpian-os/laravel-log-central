<?php

namespace Webimpian\LogCentral\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Webimpian\LogCentral\LogCentralServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LogCentralServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('log-central.url', 'https://logs.example.test/api');
        $app['config']->set('log-central.token', 'test-token');
        $app['config']->set('log-central.app', 'test-app');
        $app['config']->set('log-central.channels', 'payment_callback');

        $app['config']->set('logging.channels.payment_callback', [
            'driver' => 'single',
            'path' => storage_path('logs/payment_callback.log'),
        ]);
    }
}
