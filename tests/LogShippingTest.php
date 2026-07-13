<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Webimpian\LogCentral\Jobs\ShipLogBatch;
use Webimpian\LogCentral\Logging\CentralLogHandler;

it('buffers wrapped channel logs and ships them as one batch', function () {
    Queue::fake();

    Log::channel('payment_callback')->info('Callback received', ['ref' => 'BC-1', 'password' => 'super-secret']);
    Log::channel('payment_callback')->warning('Retrying');

    CentralLogHandler::flush();

    Queue::assertPushed(ShipLogBatch::class, function (ShipLogBatch $job) {
        return count($job->rows) === 2
            && $job->rows[0]['app'] === 'test-app'
            && $job->rows[0]['channel'] === 'payment_callback'
            && $job->rows[0]['level'] === 'info'
            && $job->rows[0]['message'] === 'Callback received'
            && str_contains($job->rows[0]['context'], '"ref":"BC-1"')
            && str_contains($job->rows[0]['context'], '"password":"[scrubbed]"')
            && $job->rows[1]['level'] === 'warning'
            && $job->rows[0]['trace_id'] !== '';
    });
});
