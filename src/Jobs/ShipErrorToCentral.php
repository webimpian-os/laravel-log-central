<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Webimpian\LogCentral\Jobs\Concerns\ShipsToLogCentral;

class ShipErrorToCentral implements ShouldQueue
{
    use InteractsWithQueue, Queueable, ShipsToLogCentral;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->shipTo('errors', [$this->payload]);
    }
}
