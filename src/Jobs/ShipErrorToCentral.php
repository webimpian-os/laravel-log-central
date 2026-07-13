<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

class ShipErrorToCentral implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        try {
            Http::withToken(config('log-central.token'))
                ->withOptions(['verify' => (bool) config('log-central.verify_ssl', true)])
                ->timeout(10)
                ->connectTimeout(5)
                ->post(rtrim((string) config('log-central.url'), '/').'/errors', [$this->payload])
                ->throw();
        } catch (Throwable) {
            $this->retryOrDrop();
        }
    }

    // Telemetry is best-effort: retry to ride out deploy windows, then drop —
    // a shipping failure must never surface in the host application.
    private function retryOrDrop(): void
    {
        $attempt = $this->attempts();

        if ($attempt < $this->tries) {
            $this->release($this->backoff[$attempt - 1] ?? 60);
        }
    }
}
