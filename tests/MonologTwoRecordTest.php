<?php

use Illuminate\Support\Facades\Queue;
use Webimpian\LogCentral\Jobs\ShipLogBatch;
use Webimpian\LogCentral\Logging\CentralLogHandler;

/**
 * On Laravel 8/9 the app ships Monolog 2, whose AbstractProcessingHandler
 * hands write() a plain array record instead of a Monolog 3 LogRecord. The
 * handler must normalize both shapes identically; here we drive the array
 * branch directly so it stays covered even while the dev suite runs Monolog 3.
 */
it('normalizes a Monolog 2 array record into a shipped log row', function () {
    $buffer = new ReflectionProperty(CentralLogHandler::class, 'buffer');
    $buffer->setValue(null, []);

    Queue::fake();

    $record = [
        'message' => 'Legacy handler record',
        'context' => ['ref' => 'BC-2', 'password' => 'super-secret'],
        'level' => 400,
        'level_name' => 'ERROR',
        'channel' => 'payment_callback',
        'datetime' => new DateTimeImmutable('2026-07-13 08:00:00', new DateTimeZone('UTC')),
        'extra' => [],
    ];

    $handler = new CentralLogHandler;
    $write = new ReflectionMethod($handler, 'write');
    $write->invoke($handler, $record);

    CentralLogHandler::flush();

    Queue::assertPushed(ShipLogBatch::class, function (ShipLogBatch $job): bool {
        $row = $job->rows[0];

        return $row['channel'] === 'payment_callback'
            && $row['level'] === 'error'
            && $row['message'] === 'Legacy handler record'
            && $row['app'] === 'test-app'
            && str_starts_with($row['timestamp'], '2026-07-13 08:00:00')
            && str_contains($row['context'], '"ref":"BC-2"')
            && str_contains($row['context'], '"password":"[scrubbed]"')
            && $row['trace_id'] !== '';
    });
});
