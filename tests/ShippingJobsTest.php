<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Webimpian\LogCentral\Jobs\ShipErrorToCentral;
use Webimpian\LogCentral\Jobs\ShipLogBatch;

it('posts log batches to the /logs endpoint with the project key', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    (new ShipLogBatch([['message' => 'hello']]))->handle();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://logs.example.test/api/logs'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->body() === '[{"message":"hello"}]';
    });
});

it('posts error payloads to the /errors endpoint', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    (new ShipErrorToCentral(['exception' => 'RuntimeException']))->handle();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://logs.example.test/api/errors'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && json_decode($request->body(), true) === [['exception' => 'RuntimeException']];
    });
});

it('releases for a later retry when shipping fails and attempts remain', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('down', 503)]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(10);

    $job = new ShipLogBatch([['message' => 'hello']]);
    $job->setJob($queueJob);
    $job->handle();
});

it('drops the batch without throwing once retries are exhausted', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('down', 503)]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $queueJob->shouldReceive('release')->never();

    $job = new ShipErrorToCentral(['exception' => 'RuntimeException']);
    $job->setJob($queueJob);
    $job->handle();
});

it('never throws on a connection failure', function () {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Resolving timed out'));

    (new ShipLogBatch([['message' => 'hello']]))->handle();
})->throwsNoExceptions();
