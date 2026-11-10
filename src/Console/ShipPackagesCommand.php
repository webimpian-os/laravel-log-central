<?php

namespace Webimpian\LogCentral\Console;

use Illuminate\Console\Command;
use Webimpian\LogCentral\Jobs\ShipPackageInventory;
use Webimpian\LogCentral\Support\PackageInventory;

class ShipPackagesCommand extends Command
{
    protected $signature = 'log-central:packages';

    protected $description = 'Send the installed Composer and npm package versions to Log Central for vulnerability checks';

    public function handle(): int
    {
        $inventory = PackageInventory::collect($this->laravel->basePath(), $this->laravel->version());

        dispatch(new ShipPackageInventory($inventory))->onQueue(config('log-central.queue'));

        $this->info('Queued '.count($inventory['packages']).' package versions for Log Central.');

        return 0;
    }
}
