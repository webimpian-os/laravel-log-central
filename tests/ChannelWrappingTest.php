<?php

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

    (new Webimpian\LogCentral\LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.custom_channel.driver'))->toBe('stack')
        ->and(config('logging.channels.single.driver'))->toBe('stack')
        ->and(config('logging.channels.stack.driver'))->toBe('stack')
        ->and(config('logging.channels.stack.channels'))->not->toContain('central')
        ->and(config('logging.channels.emergency.driver'))->not->toBe('stack');
});

it('never wraps discard channels with the star option', function () {
    config()->set('log-central.channels', '*');
    config()->set('logging.channels.blackhole', ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]);

    (new Webimpian\LogCentral\LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.null.driver'))->not->toBe('stack')
        ->and(config('logging.channels.blackhole.driver'))->not->toBe('stack');
});

it('ships nothing when url or token are missing', function () {
    config()->set('log-central.token', null);
    config()->set('logging.channels.another', ['driver' => 'single', 'path' => storage_path('logs/a.log')]);
    config()->set('log-central.channels', 'another');

    (new Webimpian\LogCentral\LogCentralServiceProvider(app()))->boot();

    expect(config('logging.channels.another.driver'))->toBe('single');
});
