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
    private const MAX_BUFFER = 200;

    /** @var list<array<string, mixed>> */
    private static array $buffer = [];

    private static bool $flushRegistered = false;

    /**
     * The record is a LogRecord on Monolog 3 (Laravel 10+) and a plain array
     * on Monolog 2 (Laravel 8/9); the parameter is left untyped so the
     * override stays compatible with both parent signatures.
     *
     * @param  \Monolog\LogRecord|array<string, mixed>  $record
     */
    protected function write($record): void
    {
        if ($record instanceof LogRecord) {
            $datetime = $record->datetime;
            $channel = $record->channel;
            $level = $record->level->getName();
            $message = $record->message;
            $context = $record->context;
        } else {
            $datetime = $record['datetime'];
            $channel = $record['channel'];
            $level = $record['level_name'];
            $message = $record['message'];
            $context = $record['context'] ?? [];
        }

        self::$buffer[] = [
            'timestamp' => $datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'app' => ErrorPayload::appSlug(),
            'environment' => (string) app()->environment(),
            'channel' => $channel,
            'level' => strtolower((string) $level),
            'message' => $message,
            'context' => ErrorPayload::jsonObject(Scrubber::scrub((array) $context)),
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
