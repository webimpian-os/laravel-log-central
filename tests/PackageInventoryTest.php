<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Webimpian\LogCentral\Jobs\ShipPackageInventory;
use Webimpian\LogCentral\Support\PackageInventory;

it('lists Composer and npm packages with their versions and dev flags', function () {
    $inventory = PackageInventory::collect(__DIR__.'/fixtures/inventory', '11.9.2');

    expect($inventory['kind'])->toBe('laravel')
        ->and($inventory['runtime'])->toBe(['php' => PHP_VERSION, 'laravel' => '11.9.2'])
        ->and($inventory['hostname'])->not->toBeEmpty()
        ->and($inventory['packages'])->toBe([
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v11.9.2', 'dev' => false],
            ['ecosystem' => 'Packagist', 'name' => 'guzzlehttp/guzzle', 'version' => '7.9.2', 'dev' => false],
            ['ecosystem' => 'Packagist', 'name' => 'pestphp/pest', 'version' => 'v3.0.0', 'dev' => true],
            ['ecosystem' => 'npm', 'name' => 'vite', 'version' => '6.0.3', 'dev' => true],
            ['ecosystem' => 'npm', 'name' => '@inertiajs/react', 'version' => '2.0.0', 'dev' => false],
            ['ecosystem' => 'npm', 'name' => 'esbuild', 'version' => '0.24.0', 'dev' => true],
        ]);
});

it('reads Composer 1 and npm lockfile v1 layouts', function () {
    expect(PackageInventory::collect(__DIR__.'/fixtures/inventory-legacy', '6.20.44')['packages'])->toBe([
        ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v6.20.44', 'dev' => false],
        ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.20', 'dev' => false],
        ['ecosystem' => 'npm', 'name' => 'webpack', 'version' => '4.46.0', 'dev' => true],
        ['ecosystem' => 'npm', 'name' => 'acorn', 'version' => '6.4.2', 'dev' => true],
    ]);
});

it('reports nothing for an app without either file', function () {
    expect(PackageInventory::collect(__DIR__.'/fixtures/does-not-exist', '11.0.0')['packages'])->toBe([]);
});

it('posts the inventory to the /packages endpoint', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    (new ShipPackageInventory(['kind' => 'laravel', 'packages' => []]))->handle();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://logs.example.test/api/packages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->body() === '{"kind":"laravel","packages":[]}';
    });
});

it('queues the inventory of the running app from the command', function () {
    Bus::fake();

    $this->artisan('log-central:packages')->assertExitCode(0);

    Bus::assertDispatched(ShipPackageInventory::class, function (ShipPackageInventory $job) {
        return $job->inventory['kind'] === 'laravel'
            && $job->inventory['runtime']['laravel'] === app()->version()
            && is_array($job->inventory['packages']);
    });
});

it('schedules the report once a day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'log-central:packages'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toMatch('/^\d{1,2} [1-4] \* \* \*$/');
});
