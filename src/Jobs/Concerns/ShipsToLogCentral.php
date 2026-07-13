<?php

namespace Webimpian\LogCentral\Jobs\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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
            $this->centralRequest()
                ->post(rtrim((string) config('log-central.url'), '/').'/'.$path, $payload)
                ->throw();
        } catch (Throwable $e) {
            if ($this->isTransient($e)) {
                $this->retryOrDrop();

                return;
            }

            $this->fail($e);
        }
    }

    private function centralRequest(): PendingRequest
    {
        $request = Http::withToken(config('log-central.token'))
            ->withOptions(['verify' => (bool) config('log-central.verify_ssl', true)])
            ->timeout(10);

        // connectTimeout() only exists on Laravel 9+; on Laravel 8 timeout() alone bounds the request.
        if (method_exists($request, 'connectTimeout')) {
            $request->connectTimeout(5);
        }

        return $request;
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = (int) $e->response->status();

            // 5xx and rate-limit statuses can clear on their own; a 4xx won't.
            return $status >= 500 || in_array($status, [408, 429], true);
        }

        return false;
    }

    private function retryOrDrop(): void
    {
        $attempt = $this->attempts();

        if ($attempt < $this->tries) {
            $this->release($this->backoff[$attempt - 1] ?? 60);
        }
    }
}
