<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

class ShipLogBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(public array $rows) {}

    public function handle(): void
    {
        Http::withToken(config('log-central.token'))
            ->withOptions(['verify' => (bool) config('log-central.verify_ssl', true)])
            ->timeout(10)
            ->connectTimeout(5)
            ->post(rtrim((string) config('log-central.url'), '/').'/logs', $this->rows)
            ->throw();
    }

    public function failed(?Throwable $exception): void
    {
        // Logs are droppable — never let shipping failures surface.
    }
}
