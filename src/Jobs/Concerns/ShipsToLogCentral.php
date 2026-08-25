<?php

namespace Webimpian\LogCentral\Jobs\Concerns;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException as GuzzleResponseException;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

trait ShipsToLogCentral
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * A transient failure is retried then dropped in silence so telemetry never
     * disturbs the host app; anything else (a bug, bad URL, or bad token) can't
     * be fixed by retrying and is failed loudly so it can't vanish. fail() never
     * routes through a log channel, so it can't loop back into shipping.
     *
     * @param  array<int|string, mixed>  $payload
     */
    protected function shipTo(string $path, array $payload): void
    {
        try {
            $this->post(rtrim((string) config('log-central.url'), '/').'/'.$path, $payload);
        } catch (Throwable $e) {
            if ($this->isTransient($e)) {
                $this->retryOrDrop();

                return;
            }

            $this->fail($e);
        }
    }

    /**
     * @param  array<int|string, mixed>  $payload
     */
    private function post(string $url, array $payload): void
    {
        $this->hasLaravelHttpClient()
            ? $this->postWithHttpClient($url, $payload)
            : $this->postWithGuzzle($url, $payload);
    }

    /**
     * Laravel's HTTP client only exists from 7.0; Laravel 6 falls back to Guzzle.
     */
    protected function hasLaravelHttpClient(): bool
    {
        return class_exists(Http::class);
    }

    /**
     * @param  array<int|string, mixed>  $payload
     */
    private function postWithHttpClient(string $url, array $payload): void
    {
        $request = Http::withToken(config('log-central.token'))
            ->withOptions(['verify' => (bool) config('log-central.verify_ssl', true)])
            ->timeout(10);

        // connectTimeout() only exists on Laravel 9+; on Laravel 8 timeout() alone bounds the request.
        if (method_exists($request, 'connectTimeout')) {
            $request->connectTimeout(5);
        }

        $request->post($url, $payload)->throw();
    }

    /**
     * `http_errors` keeps Guzzle throwing on 4xx/5xx so this path fails the same
     * way ->throw() does on the Laravel client, and isTransient() can treat both.
     *
     * @param  array<int|string, mixed>  $payload
     */
    private function postWithGuzzle(string $url, array $payload): void
    {
        if (! class_exists(GuzzleClient::class)) {
            throw new RuntimeException(
                'Log Central needs guzzlehttp/guzzle to ship telemetry on Laravel 6, which has no HTTP client of its own. Run: composer require guzzlehttp/guzzle'
            );
        }

        $this->guzzleClient()->request('POST', $url, [
            'json' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.config('log-central.token'),
                'Accept' => 'application/json',
            ],
            'verify' => (bool) config('log-central.verify_ssl', true),
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => true,
        ]);
    }

    protected function guzzleClient(): GuzzleClient
    {
        return new GuzzleClient;
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException || $e instanceof GuzzleConnectException) {
            return true;
        }

        $status = $this->statusFrom($e);

        // 5xx and rate-limit statuses can clear on their own; a 4xx won't.
        return $status !== null && ($status >= 500 || in_array($status, [408, 429], true));
    }

    private function statusFrom(Throwable $e): ?int
    {
        if ($e instanceof RequestException) {
            return (int) $e->response->status();
        }

        if ($e instanceof GuzzleResponseException) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    private function retryOrDrop(): void
    {
        $attempt = $this->attempts();

        if ($attempt < $this->tries) {
            $this->release($this->backoff[$attempt - 1] ?? 60);
        }
    }
}
