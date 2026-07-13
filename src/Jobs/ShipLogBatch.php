<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Webimpian\LogCentral\Jobs\Concerns\ShipsToLogCentral;

class ShipLogBatch implements ShouldQueue
{
    use InteractsWithQueue, Queueable, ShipsToLogCentral;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(public array $rows) {}

    public function handle(): void
    {
        $this->shipTo('logs', $this->rows);
    }
}
