<?php

namespace Webimpian\LogCentral;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webimpian\LogCentral\Http\ApiRequestRecorder;
use Webimpian\LogCentral\Listeners\ShipExceptionListener;
use Webimpian\LogCentral\Logging\CentralLogHandler;

class LogCentralServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-central.php', 'log-central');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/log-central.php' => config_path('log-central.php'),
        ], 'log-central-config');

        if (! $this->enabled()) {
            return;
        }

        $this->registerCentralChannel();
        $this->wrapChannels();
        $this->registerApiRecorder();

        Event::listen(MessageLogged::class, ShipExceptionListener::class);
    }

    private function registerApiRecorder(): void
    {
        if (trim((string) config('log-central.api_paths')) === '') {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        if (! method_exists($kernel, 'pushMiddleware')) {
            return;
        }

        $kernel->pushMiddleware(ApiRequestRecorder::class);

        DB::listen(fn (QueryExecuted $query) => ApiRequestRecorder::recordQuery((float) $query->time));
    }

    private function enabled(): bool
    {
        return (bool) config('log-central.enabled')
            && filled(config('log-central.url'))
            && filled(config('log-central.token'));
    }

    private function registerCentralChannel(): void
    {
        config(['logging.channels.central' => [
            'driver' => 'monolog',
            'handler' => CentralLogHandler::class,
        ]]);
    }

    /**
     * Discard sinks (Laravel's null channel, NullHandler-backed channels)
     * exist to throw entries away — the * wildcard must not resurrect them.
     */
    private function isDiscardChannel(string $name, array $channel): bool
    {
        return $name === 'null' || ($channel['handler'] ?? null) === \Monolog\Handler\NullHandler::class;
    }

    private function wrapChannels(): void
    {
        $configured = trim((string) config('log-central.channels'));

        if ($configured === '') {
            return;
        }

        $channels = config('logging.channels', []);

        $names = $configured === '*'
            ? array_keys(array_filter(
                $channels,
                fn ($channel, $name): bool => ! $this->isDiscardChannel((string) $name, (array) $channel),
                ARRAY_FILTER_USE_BOTH,
            ))
            : array_filter(array_map(trim(...), explode(',', $configured)));

        foreach ($names as $name) {
            $channel = $channels[$name] ?? null;

            if ($channel === null || in_array($name, ['central', 'emergency'], true) || ($channel['driver'] ?? null) === 'stack') {
                continue;
            }

            config([
                "logging.channels.{$name}_local" => $channel,
                "logging.channels.{$name}" => [
                    'driver' => 'stack',
                    'name' => $name,
                    'channels' => ["{$name}_local", 'central'],
                    'ignore_exceptions' => true,
                ],
            ]);
        }
    }
}
