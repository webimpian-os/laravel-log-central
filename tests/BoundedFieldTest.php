<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Webimpian\LogCentral\Jobs\ShipLogBatch;
use Webimpian\LogCentral\Logging\CentralLogHandler;
use Webimpian\LogCentral\Support\ErrorPayload;

it('leaves small json objects untouched', function () {
    expect(ErrorPayload::boundedJsonObject(['a' => 1]))->toBe('{"a":1}');
});

it('replaces oversized json objects with a marker that keeps a preview', function () {
    $result = ErrorPayload::boundedJsonObject(['ref' => 'BC-1', 'blob' => str_repeat('x', 300_000)]);
    $decoded = json_decode($result, true);

    expect(strlen($result))->toBeLessThanOrEqual(262144)
        ->and($decoded['_truncated'])->toBeTrue()
        ->and($decoded['_bytes'])->toBeGreaterThan(300_000)
        ->and($decoded['_preview'])->toContain('BC-1');
});

it('caps oversized log context before shipping', function () {
    Queue::fake();

    Log::channel('payment_callback')->info('Callback received', ['blob' => str_repeat('x', 300_000)]);

    CentralLogHandler::flush();

    Queue::assertPushed(ShipLogBatch::class, function (ShipLogBatch $job) {
        return strlen($job->rows[0]['context']) <= 262144
            && str_contains($job->rows[0]['context'], '_truncated');
    });
});
