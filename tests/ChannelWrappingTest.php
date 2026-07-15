<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\NullHandler;
use Webimpian\LogCentral\Jobs\ShipLogBatch;
use Webimpian\LogCentral\LogCentralServiceProvider;
use Webimpian\LogCentral\Logging\CentralLogHandler;

it('wraps configured channels in a stack with the central channel', function () {
    $channel = config('logging.channels.payment_callback');

    expect($channel['driver'])->toBe('stack')
        ->and($channel['channels'])->toBe(['payment_callback_local', 'central'])
        ->and($channel['ignore_exceptions'])->toBeTrue();

    expect(config('logging.channels.payment_callback_local.driver'))->toBe('single')
        ->and(config('logging.channels.central.driver'))->toBe('monolog');
});

it('leaves unlisted channels untouched', function () {
    expect(config('logging.channels.single.driver'))->toBe('single');
});

it('wraps every non-stack channel with the star option', function () {
    config()->set('log-central.channels', '*');
    config()->set('logging.channels.custom_channel', ['driver' => 'single', 'path' => storage_path('logs/custom.log')]);

    (new LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.custom_channel.driver'))->toBe('stack')
        ->and(config('logging.channels.single.driver'))->toBe('stack')
        ->and(config('logging.channels.stack.driver'))->toBe('stack')
        ->and(config('logging.channels.stack.channels'))->not->toContain('central')
        ->and(config('logging.channels.emergency.driver'))->not->toBe('stack');
});

it('never wraps discard channels with the star option', function () {
    config()->set('log-central.channels', '*');
    config()->set('logging.channels.blackhole', ['driver' => 'monolog', 'handler' => NullHandler::class]);

    (new LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.null.driver'))->not->toBe('stack')
        ->and(config('logging.channels.blackhole.driver'))->not->toBe('stack');
});

it('stays idempotent when the wrap is applied twice (config:cache re-wrapping)', function () {
    (new ReflectionProperty(CentralLogHandler::class, 'buffer'))->setValue(null, []);

    config()->set('log-central.channels', '*');

    // Two boots simulate `config:cache` persisting the already-wrapped channels,
    // then the provider wrapping them again on the next (cached) request. The
    // internal `_local` twin must not be re-wrapped into another stack, or the
    // channel would resolve `central` twice and ship every record twice.
    (new LogCentralServiceProvider(app()))->boot();
    (new LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.payment_callback_local.driver'))->toBe('single');

    Queue::fake();

    Log::channel('payment_callback')->error('Boom');
    CentralLogHandler::flush();

    Queue::assertPushed(ShipLogBatch::class, fn (ShipLogBatch $job): bool => count($job->rows) === 1);
});

it('ships nothing when url or token are missing', function () {
    config()->set('log-central.token', null);
    config()->set('logging.channels.another', ['driver' => 'single', 'path' => storage_path('logs/a.log')]);
    config()->set('log-central.channels', 'another');

    (new LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.another.driver'))->toBe('single');
});
