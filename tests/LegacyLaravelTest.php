<?php

namespace Webimpian\LogCentral\Tests;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Monolog\Logger;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionMethod;
use ReflectionProperty;
use Webimpian\LogCentral\Jobs\ShipLogBatch;
use Webimpian\LogCentral\LogCentralServiceProvider;
use Webimpian\LogCentral\Logging\CentralLogHandler;
use Webimpian\LogCentral\Support\ErrorPayload;
use Webimpian\LogCentral\Support\Scrubber;

/**
 * Guards the Laravel 6 floor: there the framework has no HTTP client, so
 * shipping falls back to Guzzle, and Monolog 1 hands over a mutable datetime.
 * Nothing here fakes the old framework — CI runs it against a real Laravel 6
 * install, and it skips itself everywhere else.
 */
class LegacyLaravelTest extends Orchestra
{
    /** @var array<int, array<string, mixed>> */
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (version_compare($this->app->version(), '7.0', '>=')) {
            $this->markTestSkipped('Laravel 6 floor only; the HTTP client exists from 7.0.');
        }
    }

    protected function getPackageProviders($app)
    {
        return [LogCentralServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
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

    /** @param list<mixed> $queue */
    private function jobWithGuzzle(array $queue): ShipLogBatch
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $job = new class([['message' => 'hello']]) extends ShipLogBatch
        {
            /** @var Client */
            public $testClient;

            protected function guzzleClient(): Client
            {
                return $this->testClient;
            }
        };

        $job->testClient = new Client(['handler' => $stack]);

        return $job;
    }

    public function test_this_really_is_laravel_6_without_an_http_client()
    {
        $this->assertStringStartsWith('6.', $this->app->version());
        $this->assertFalse(class_exists(Http::class));
        $this->assertSame(1, Logger::API, 'Laravel 6 must be exercised against Monolog 1.');
    }

    public function test_it_ships_through_guzzle_with_the_project_key()
    {
        $this->jobWithGuzzle([new Response(202, [], '{"accepted":1}')])->handle();

        $this->assertCount(1, $this->history);

        $request = $this->history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://logs.example.test/api/logs', (string) $request->getUri());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('[{"message":"hello"}]', (string) $request->getBody());
    }

    public function test_it_releases_for_retry_on_a_transient_failure()
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(10);

        $job = $this->jobWithGuzzle([new Response(503, [], 'down')]);
        $job->setJob($queueJob);
        $job->handle();
    }

    public function test_it_treats_a_connection_failure_as_transient()
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(10);

        $failure = new ConnectException('Resolving timed out', new PsrRequest('POST', 'https://logs.example.test/api/logs'));

        $job = $this->jobWithGuzzle([$failure]);
        $job->setJob($queueJob);
        $job->handle();
    }

    public function test_it_fails_the_job_when_retrying_cannot_help()
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('fail')->once();
        $queueJob->shouldReceive('release')->never();

        $job = $this->jobWithGuzzle([new Response(401, [], 'unauthorized')]);
        $job->setJob($queueJob);
        $job->handle();
    }

    public function test_a_monolog_one_record_ships_without_mutating_the_shared_datetime()
    {
        $buffer = new ReflectionProperty(CentralLogHandler::class, 'buffer');
        $buffer->setAccessible(true);
        $buffer->setValue(null, []);

        Queue::fake();

        $datetime = new DateTime('2026-08-24 10:00:00', new DateTimeZone('Asia/Kuala_Lumpur'));

        $record = [
            'datetime' => $datetime,
            'channel' => 'testing',
            'level_name' => 'ERROR',
            'message' => 'legacy boom',
            'context' => ['password' => 'hunter2'],
        ];

        $handler = new CentralLogHandler;
        $write = new ReflectionMethod($handler, 'write');
        $write->setAccessible(true);
        $write->invoke($handler, $record);

        CentralLogHandler::flush();

        // The handler must not rewrite the timestamp the other handlers still hold.
        $this->assertSame('Asia/Kuala_Lumpur', $datetime->getTimezone()->getName());
        $this->assertSame('2026-08-24 10:00:00', $datetime->format('Y-m-d H:i:s'));

        Queue::assertPushed(ShipLogBatch::class, function (ShipLogBatch $job) {
            $row = $job->rows[0];

            return $row['timestamp'] === '2026-08-24 02:00:00.000'
                && $row['level'] === 'error'
                && $row['message'] === 'legacy boom'
                && $row['app'] === 'test-app'
                && strpos($row['context'], 'scrubbed') !== false;
        });
    }

    public function test_it_wraps_configured_channels_with_the_central_channel()
    {
        $this->assertSame('stack', config('logging.channels.payment_callback.driver'));
        $this->assertSame(['payment_callback_local', 'central'], config('logging.channels.payment_callback.channels'));
        $this->assertSame('single', config('logging.channels.payment_callback_local.driver'));
    }

    public function test_it_builds_an_error_payload_and_scrubs_secrets()
    {
        $payload = ErrorPayload::fromThrowable(new \RuntimeException('boom'));

        $this->assertSame('test-app', $payload['app']);
        $this->assertSame(\RuntimeException::class, $payload['exception']);
        $this->assertSame('boom', $payload['message']);
        $this->assertSame(0, $payload['user_id']);

        $this->assertSame(
            ['token' => '[scrubbed]', 'safe' => 'kept'],
            Scrubber::scrub(['token' => 'abc123', 'safe' => 'kept'])
        );
    }
}
