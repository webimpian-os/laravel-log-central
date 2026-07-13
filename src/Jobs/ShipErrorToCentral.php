<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

class ShipErrorToCentral implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        Http::withToken(config('log-central.token'))
            ->withOptions(['verify' => (bool) config('log-central.verify_ssl', true)])
            ->timeout(10)
            ->connectTimeout(5)
            ->post(rtrim((string) config('log-central.url'), '/').'/errors', [$this->payload])
            ->throw();
    }

    public function failed(?Throwable $exception): void
    {
        // Errors are droppable — never let shipping failures surface.
    }
}
