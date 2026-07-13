<?php

namespace Webimpian\LogCentral\Support;

/**
 * One correlation ID per PHP process (i.e. per HTTP request or console run),
 * shared by the log handler and the API request recorder so a recorded
 * request can be tied back to every log line it produced.
 */
class TraceId
{
    private static ?string $id = null;

    public static function current(): string
    {
        return self::$id ??= bin2hex(random_bytes(8));
    }

    public static function reset(): void
    {
        self::$id = null;
    }
}
