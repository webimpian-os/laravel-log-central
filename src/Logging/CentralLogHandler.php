<?php

namespace Webimpian\LogCentral\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Webimpian\LogCentral\Jobs\ShipLogBatch;
use Webimpian\LogCentral\Support\ErrorPayload;
use Webimpian\LogCentral\Support\Scrubber;
use Webimpian\LogCentral\Support\TraceId;

class CentralLogHandler extends AbstractProcessingHandler
{
    private const int MAX_BUFFER = 200;

    /** @var list<array<string, mixed>> */
    private static array $buffer = [];

    private static bool $flushRegistered = false;

    protected function write(LogRecord $record): void
    {
        self::$buffer[] = [
            'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'app' => ErrorPayload::appSlug(),
            'environment' => (string) app()->environment(),
            'channel' => $record->channel,
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'context' => ErrorPayload::jsonObject(Scrubber::scrub($record->context)),
            'hostname' => gethostname() ?: '',
            'trace_id' => TraceId::current(),
        ];

        if (count(self::$buffer) >= self::MAX_BUFFER) {
            self::flush();
        }

        $this->registerFlush();
    }

    public static function flush(): void
    {
        if (self::$buffer === []) {
            return;
        }

        $rows = self::$buffer;
        self::$buffer = [];

        rescue(function () use ($rows) {
            dispatch(new ShipLogBatch($rows))->onQueue(config('log-central.queue'));
        }, report: false);
    }

    private function registerFlush(): void
    {
        if (self::$flushRegistered) {
            return;
        }

        self::$flushRegistered = true;

        app()->terminating(fn () => self::flush());
        register_shutdown_function(fn () => self::flush());
    }
}
