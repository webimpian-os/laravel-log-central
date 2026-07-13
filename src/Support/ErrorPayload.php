<?php

namespace Webimpian\LogCentral\Support;

use Illuminate\Support\Str;
use Throwable;

class ErrorPayload
{
    /**
     * Builds one /api/errors entry. Must run synchronously (request context
     * is gone by the time a queued job executes).
     *
     * @return array<string, mixed>
     */
    public static function fromThrowable(Throwable $exception): array
    {
        $request = app()->runningInConsole() ? null : request();

        $user = rescue(fn () => auth()->user(), null, false);
        $userId = $user?->getAuthIdentifier();

        $userDetails = $user === null ? [] : array_filter([
            'id' => $userId,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ], fn ($value) => $value !== null);

        return [
            'timestamp' => now('UTC')->format('Y-m-d H:i:s.v'),
            'fingerprint' => md5($exception::class.'|'.$exception->getFile().'|'.$exception->getLine()),
            'app' => self::appSlug(),
            'environment' => (string) app()->environment(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'url' => $request?->fullUrl() ?? '',
            'method' => $request?->method() ?? 'CLI',
            'user_id' => is_numeric($userId) ? (int) $userId : 0,
            'user' => self::jsonObject($userDetails),
            'ip' => (string) ($request?->ip() ?? ''),
            'user_agent' => (string) ($request?->userAgent() ?? ''),
            'referrer' => (string) ($request?->header('referer') ?? ''),
            'input' => self::jsonObject(Scrubber::scrub($request?->input() ?? [])),
            'hostname' => gethostname() ?: '',
            'entrypoint' => self::entrypoint(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function jsonObject(array $data): string
    {
        return json_encode($data === [] ? new \stdClass : $data) ?: '{}';
    }

    public static function appSlug(): string
    {
        return config('log-central.app') ?: Str::slug((string) config('app.name'));
    }

    private static function entrypoint(): string
    {
        if (! app()->runningInConsole()) {
            return 'http';
        }

        $command = $_SERVER['argv'][1] ?? '';

        return in_array($command, ['queue:work', 'queue:listen', 'horizon', 'horizon:supervisor', 'horizon:work'], true)
            ? 'queue'
            : 'console';
    }
}
