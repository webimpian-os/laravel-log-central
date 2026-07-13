<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Webimpian\LogCentral\Jobs\ShipErrorToCentral;
use Webimpian\LogCentral\Jobs\ShipLogBatch;

it('posts log batches to the /logs endpoint with the project key', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    (new ShipLogBatch([['message' => 'hello']]))->handle();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://logs.example.test/api/logs'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->body() === '[{"message":"hello"}]';
    });
});

it('posts error payloads to the /errors endpoint', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    (new ShipErrorToCentral(['exception' => 'RuntimeException']))->handle();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://logs.example.test/api/errors'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && json_decode($request->body(), true) === [['exception' => 'RuntimeException']];
    });
});

it('throws on server errors so the queue retries', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('down', 503)]);

    (new ShipLogBatch([['message' => 'hello']]))->handle();
})->throws(Illuminate\Http\Client\RequestException::class);
