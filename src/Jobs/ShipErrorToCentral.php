<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Webimpian\LogCentral\Jobs\Concerns\ShipsToLogCentral;

class ShipErrorToCentral implements ShouldQueue
{
    use InteractsWithQueue, Queueable, ShipsToLogCentral;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $this->shipTo('errors', [$this->payload]);
    }
}
