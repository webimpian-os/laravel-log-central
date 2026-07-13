<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Webimpian\LogCentral\Http\ApiRequestRecorder;
use Webimpian\LogCentral\Jobs\ShipApiRequestBatch;

it('records api requests with scrubbed response bodies', function () {
    Queue::fake();

    Route::get('api/ping', fn () => response()->json(['ok' => true, 'token' => 'secret-token']));

    $this->getJson('api/ping')->assertSuccessful();

    ApiRequestRecorder::flush();

    Queue::assertPushed(ShipApiRequestBatch::class, function (ShipApiRequestBatch $job) {
        $row = $job->rows[0];

        return count($job->rows) === 1
            && $row['app'] === 'test-app'
            && $row['method'] === 'GET'
            && $row['route'] === 'api/ping'
            && $row['status'] === 200
            && $row['duration_ms'] >= 0
            && str_contains($row['response'], '"ok":true')
            && str_contains($row['response'], '"token":"[scrubbed]"');
    });
});

it('records scrubbed request payloads', function () {
    Queue::fake();

    Route::post('api/orders', fn () => response()->json(['created' => true], 201));

    $this->postJson('api/orders', ['amount' => 100, 'password' => 'hunter2'])->assertStatus(201);

    ApiRequestRecorder::flush();

    Queue::assertPushed(ShipApiRequestBatch::class, function (ShipApiRequestBatch $job) {
        $row = $job->rows[0];

        return $row['method'] === 'POST'
            && $row['status'] === 201
            && str_contains($row['payload'], '"amount":100')
            && str_contains($row['payload'], '"password":"[scrubbed]"');
    });
});

it('captures request flow metrics', function () {
    Queue::fake();

    Route::get('api/flow', function () {
        DB::select('select 1 as n');

        return response()->json(['ok' => true]);
    });

    $this->getJson('api/flow')->assertSuccessful();

    ApiRequestRecorder::flush();

    Queue::assertPushed(ShipApiRequestBatch::class, function (ShipApiRequestBatch $job) {
        $row = $job->rows[0];

        return strlen((string) $row['trace_id']) === 16
            && $row['db_query_count'] >= 1
            && $row['db_time_ms'] >= 0
            && $row['memory_mb'] > 0
            && $row['response_size'] > 0
            && is_string($row['user_agent'])
            && is_string($row['referer'])
            && $row['user'] === '{}';
    });
});

it('captures the authenticated user name and email', function () {
    Queue::fake();

    $user = new Illuminate\Auth\GenericUser(['id' => 7, 'name' => 'Jane Doe', 'email' => 'jane@example.com']);

    Route::get('api/me', fn () => response()->json(['ok' => true]));

    $this->actingAs($user)->getJson('api/me')->assertSuccessful();

    ApiRequestRecorder::flush();

    Queue::assertPushed(ShipApiRequestBatch::class, function (ShipApiRequestBatch $job) {
        $person = json_decode($job->rows[0]['user'], true);

        return $job->rows[0]['user_id'] === '7'
            && $person['id'] === 7
            && $person['name'] === 'Jane Doe'
            && $person['email'] === 'jane@example.com';
    });
});

it('ignores requests outside the configured paths', function () {
    Queue::fake();

    Route::get('web-page', fn () => response()->json(['ok' => true]));

    $this->getJson('web-page')->assertSuccessful();

    ApiRequestRecorder::flush();

    Queue::assertNothingPushed();
});

it('captures failed bodies only in failed mode', function () {
    config()->set('log-central.api_response', 'failed');
    Queue::fake();

    Route::get('api/ok', fn () => response()->json(['fine' => true]));
    Route::get('api/boom', fn () => response()->json(['message' => 'nope'], 500));

    $this->getJson('api/ok')->assertSuccessful();
    $this->getJson('api/boom')->assertStatus(500);

    ApiRequestRecorder::flush();

    Queue::assertPushed(ShipApiRequestBatch::class, function (ShipApiRequestBatch $job) {
        $rows = collect($job->rows)->keyBy('route');

        return $rows['api/ok']['response'] === ''
            && $rows['api/ok']['status'] === 200
            && str_contains($rows['api/boom']['response'], 'nope')
            && $rows['api/boom']['status'] === 500;
    });
});

it('records nothing when api paths are empty', function () {
    config()->set('log-central.api_paths', '');
    Queue::fake();

    Route::get('api/ping', fn () => response()->json(['ok' => true]));

    $this->getJson('api/ping')->assertSuccessful();

    ApiRequestRecorder::flush();

    Queue::assertNothingPushed();
});
