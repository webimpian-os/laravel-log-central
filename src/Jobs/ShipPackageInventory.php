<?php

namespace Webimpian\LogCentral\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Webimpian\LogCentral\Jobs\Concerns\ShipsToLogCentral;

class ShipPackageInventory implements ShouldQueue
{
    use InteractsWithQueue, Queueable, ShipsToLogCentral;

    public array $inventory;

    public function __construct(array $inventory)
    {
        $this->inventory = $inventory;
    }

    public function handle(): void
    {
        $this->shipTo('packages', $this->inventory);
    }
}
