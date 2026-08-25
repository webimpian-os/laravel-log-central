<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Webimpian\LogCentral\Jobs\ShipLogBatch;

/**
 * Laravel 6 has no HTTP client, so shipping falls back to Guzzle. The dev suite
 * runs on a modern Laravel, so the fallback is forced through the seams rather
 * than by uninstalling the facade.
 *
 * @param  list<Response|Throwable>  $queue
 * @param  array<int, array<string, mixed>>  $history
 */
function guzzleFallbackJob(array $queue, array &$history = []): ShipLogBatch
{
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    $job = new class([['message' => 'hello']]) extends ShipLogBatch
    {
        public Client $testClient;

        protected function hasLaravelHttpClient(): bool
        {
            return false;
        }

        protected function guzzleClient(): Client
        {
            return $this->testClient;
        }
    };

    $job->testClient = new Client(['handler' => $stack]);

    return $job;
}

it('ships through guzzle with the project key when Laravel has no HTTP client', function () {
    $history = [];

    guzzleFallbackJob([new Response(202, [], '{"accepted":1}')], $history)->handle();

    expect($history)->toHaveCount(1);

    $request = $history[0]['request'];

    expect((string) $request->getUri())->toBe('https://logs.example.test/api/logs')
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test-token')
        ->and((string) $request->getBody())->toBe('[{"message":"hello"}]');
});

it('releases for a later retry when guzzle reports a transient failure', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(10);

    $job = guzzleFallbackJob([new Response(503, [], 'down')]);
    $job->setJob($queueJob);
    $job->handle();
});

it('fails the job when guzzle reports a rejection that retrying cannot fix', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once();
    $queueJob->shouldReceive('release')->never();

    $job = guzzleFallbackJob([new Response(401, [], 'unauthorized')]);
    $job->setJob($queueJob);
    $job->handle();
});

it('treats a guzzle connection failure as transient', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(10);

    $connectionFailure = new ConnectException(
        'Resolving timed out',
        new Request('POST', 'https://logs.example.test/api/logs')
    );

    $job = guzzleFallbackJob([$connectionFailure]);
    $job->setJob($queueJob);
    $job->handle();
});
