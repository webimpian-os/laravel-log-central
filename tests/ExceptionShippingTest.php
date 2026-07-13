<?php

use Illuminate\Support\Facades\Queue;
use Webimpian\LogCentral\Jobs\ShipErrorToCentral;

it('dispatches a shipping job when an exception is reported', function () {
    Queue::fake();

    $exception = new RuntimeException('boom');
    $line = __LINE__ - 1;

    report($exception);

    Queue::assertPushed(ShipErrorToCentral::class, function (ShipErrorToCentral $job) use ($line) {
        return $job->payload['fingerprint'] === md5(RuntimeException::class.'|'.__FILE__.'|'.$line)
            && $job->payload['app'] === 'test-app'
            && $job->payload['exception'] === RuntimeException::class
            && $job->payload['message'] === 'boom'
            && $job->payload['user_id'] === 0
            && $job->payload['user'] === '{}'
            && $job->payload['input'] === '{}'
            && in_array($job->payload['entrypoint'], ['http', 'console', 'queue'], true);
    });
});

it('does not ship plain log messages as errors', function () {
    Queue::fake();

    logger()->error('just a message, no exception');

    Queue::assertNotPushed(ShipErrorToCentral::class);
});

it('never ships exceptions thrown by the package itself', function () {
    Queue::fake();

    $exception = new RuntimeException('internal');
    $reflection = new ReflectionProperty(Exception::class, 'file');
    $reflection->setValue($exception, dirname(__DIR__).'/src/Jobs/ShipLogBatch.php');

    report($exception);

    Queue::assertNotPushed(ShipErrorToCentral::class);
});
