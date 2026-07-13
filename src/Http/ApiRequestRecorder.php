<?php

namespace Webimpian\LogCentral\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webimpian\LogCentral\Jobs\ShipApiRequestBatch;
use Webimpian\LogCentral\Support\ErrorPayload;
use Webimpian\LogCentral\Support\Scrubber;
use Webimpian\LogCentral\Support\TraceId;

/**
 * Records API requests during terminate(), after the response has been sent
 * to the client, so nothing here adds latency to the request itself.
 */
class ApiRequestRecorder
{
    private const MAX_BUFFER = 200;

    private const MAX_RESPONSE_BYTES = 4096;

    private const MAX_AGENT_BYTES = 512;

    /** @var list<array<string, mixed>> */
    private static array $buffer = [];

    private static bool $flushRegistered = false;

    private static bool $counting = false;

    private static int $dbQueries = 0;

    private static float $dbTimeMs = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRecord($request)) {
            self::$counting = true;
            self::$dbQueries = 0;
            self::$dbTimeMs = 0.0;
        }

        return $next($request);
    }

    /**
     * Accumulates DB timings for the current request. Registered once as a
     * DB::listen callback; a no-op unless the request is being recorded.
     */
    public static function recordQuery(float $timeMs): void
    {
        if (self::$counting) {
            self::$dbQueries++;
            self::$dbTimeMs += $timeMs;
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldRecord($request)) {
            return;
        }

        rescue(function () use ($request, $response) {
            self::$buffer[] = [
                'timestamp' => now('UTC')->format('Y-m-d H:i:s.v'),
                'app' => ErrorPayload::appSlug(),
                'environment' => (string) app()->environment(),
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $this->durationMs($request),
                'user_id' => (string) ($request->user()?->getAuthIdentifier() ?? ''),
                'user' => $this->userObject($request),
                'ip' => (string) $request->ip(),
                'hostname' => gethostname() ?: '',
                'response' => $this->responseBody($response),
                'payload' => $this->payloadBody($request, $response),
                'trace_id' => TraceId::current(),
                'db_query_count' => self::$dbQueries,
                'db_time_ms' => round(self::$dbTimeMs, 2),
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'user_agent' => mb_strcut((string) $request->userAgent(), 0, self::MAX_AGENT_BYTES),
                'referer' => mb_strcut((string) $request->headers->get('referer', ''), 0, self::MAX_AGENT_BYTES),
                'response_size' => $this->responseSize($response),
            ];

            if (count(self::$buffer) >= self::MAX_BUFFER) {
                self::flush();
            }

            self::registerFlush();
        }, report: false);
    }

    public static function flush(): void
    {
        if (self::$buffer === []) {
            return;
        }

        $rows = self::$buffer;
        self::$buffer = [];

        rescue(function () use ($rows) {
            dispatch(new ShipApiRequestBatch($rows))->onQueue(config('log-central.queue'));
        }, report: false);
    }

    private function shouldRecord(Request $request): bool
    {
        if (! (bool) config('log-central.enabled') || blank(config('log-central.url')) || blank(config('log-central.token'))) {
            return false;
        }

        $patterns = array_filter(array_map(trim(...), explode(',', (string) config('log-central.api_paths'))));

        return $patterns !== [] && $request->is(...$patterns);
    }

    private function durationMs(Request $request): int
    {
        $start = defined('LARAVEL_START')
            ? (float) LARAVEL_START
            : (float) $request->server('REQUEST_TIME_FLOAT', 0);

        return $start > 0.0 ? max(0, (int) round((microtime(true) - $start) * 1000)) : 0;
    }

    /**
     * The authenticated user as a small {id, name, email} object so the
     * dashboard can show who a request belongs to, not just an opaque ID.
     */
    private function userObject(Request $request): string
    {
        $user = rescue(fn () => $request->user(), null, false);

        return ErrorPayload::jsonObject(array_filter([
            'id' => $user?->getAuthIdentifier(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ], fn ($value) => $value !== null));
    }

    private function responseSize(Response $response): int
    {
        $length = $response->headers->get('Content-Length');

        if ($length !== null && is_numeric($length)) {
            return max(0, (int) $length);
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return 0;
        }

        return strlen((string) $response->getContent());
    }

    private function payloadBody(Request $request, Response $response): string
    {
        $mode = (string) config('log-central.api_payload', 'all');

        if ($mode === 'none' || ($mode === 'failed' && $response->getStatusCode() < 400)) {
            return '';
        }

        $input = $request->input();

        if (! is_array($input) || $input === []) {
            return '';
        }

        $encoded = json_encode(Scrubber::scrub($input), JSON_UNESCAPED_UNICODE) ?: '';

        return mb_strcut($encoded, 0, self::MAX_RESPONSE_BYTES);
    }

    private function responseBody(Response $response): string
    {
        $mode = (string) config('log-central.api_response', 'all');

        if ($mode === 'none' || ($mode === 'failed' && $response->getStatusCode() < 400)) {
            return '';
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return '';
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return '';
        }

        $content = (string) $response->getContent();

        if ($content === '') {
            return '';
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        if (is_array($decoded)) {
            $content = json_encode(Scrubber::scrub($decoded), JSON_UNESCAPED_UNICODE) ?: '';
        }

        return mb_strcut($content, 0, self::MAX_RESPONSE_BYTES);
    }

    private static function registerFlush(): void
    {
        if (self::$flushRegistered) {
            return;
        }

        self::$flushRegistered = true;

        app()->terminating(fn () => self::flush());
        register_shutdown_function(fn () => self::flush());
    }
}
