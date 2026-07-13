# Laravel Log Central

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webimpian/laravel-log-central.svg)](https://packagist.org/packages/webimpian/laravel-log-central)
[![Total Downloads](https://img.shields.io/packagist/dt/webimpian/laravel-log-central.svg)](https://packagist.org/packages/webimpian/laravel-log-central)
[![License](https://img.shields.io/packagist/l/webimpian/laravel-log-central.svg)](LICENSE.md)

Ship exceptions, log entries, and API request metrics from any Laravel application to a [Log Central](https://log.dev-aplikasiniaga.com) server. Install the package, set a few environment variables, and telemetry flows automatically over queued, batched background jobs — no code changes required.

## Highlights

- **Automatic exception tracking** — every reported exception (web, queue, Artisan, scheduler, or an explicit `report($e)`) is shipped with its fingerprint, stack trace, request context, and authenticated user.
- **Centralised log channels** — nominate the channels you care about (or `*` for all); their entries stream to Log Central while your local log files keep working unchanged.
- **Zero-touch API monitoring** — every `api/*` request is recorded with its method, route, status, and duration, plus the scrubbed response and payload, DB query count and time, peak memory, user-agent, and the authenticated user. Captured after the response is sent, so request latency is never affected.
- **Non-blocking by design** — all telemetry is dispatched to queued jobs, batched, and retried with capped backoff. Delivery failures are handled silently and can never loop or disrupt the host application.
- **Sensitive data scrubbed at the source** — passwords, tokens, keys, and card numbers are replaced with `[scrubbed]` before anything leaves your application.

## Requirements

- PHP 8.2 or higher
- Laravel 11, 12, or 13
- A running queue worker (Redis / Horizon recommended)
- A Log Central project key — create an app on the [dashboard](https://log.dev-aplikasiniaga.com) to obtain one

## Installation

```bash
composer require webimpian/laravel-log-central
```

Add the connection details to your `.env`:

```env
CENTRAL_LOG_URL=https://log.dev-aplikasiniaga.com/api
CENTRAL_LOG_TOKEN=your-project-key
CENTRAL_LOG_APP=your-app-slug
# * ships every channel, or give a comma-separated list
CENTRAL_LOG_CHANNELS=*
```

API monitoring is enabled by default for `api/*`. The following are optional — use them to narrow the monitored paths or control what is stored:

```env
# path globs to monitor (comma-separated); empty disables
CENTRAL_LOG_API_PATHS=api/*
# response bodies to store: all | failed | none
CENTRAL_LOG_API_RESPONSE=all
# request payloads to store: all | failed | none
CENTRAL_LOG_API_PAYLOAD=all
# dispatch shipping jobs onto a dedicated queue
CENTRAL_LOG_QUEUE=logs
```

The service provider registers itself automatically — no further wiring is required.

## Configuration

Publish the configuration file to customise scrub keys, the shipping queue, or defaults:

```bash
php artisan vendor:publish --tag=log-central-config
```

| Variable | Default | Purpose |
|---|---|---|
| `CENTRAL_LOG_URL` | — | Base API URL of the Log Central server |
| `CENTRAL_LOG_TOKEN` | — | The application's project key |
| `CENTRAL_LOG_APP` | slug of `app.name` | Must match the slug registered on Log Central |
| `CENTRAL_LOG_CHANNELS` | *(empty)* | Channels to ship; `*` = all (exceptions ship regardless) |
| `CENTRAL_LOG_API_PATHS` | `api/*` | Paths whose API traffic is recorded; empty disables monitoring |
| `CENTRAL_LOG_API_RESPONSE` | `all` | Response bodies to include: `all`, `failed` (4xx/5xx only), or `none` |
| `CENTRAL_LOG_API_PAYLOAD` | `all` | Request payloads to include: `all`, `failed` (4xx/5xx only), or `none` |
| `CENTRAL_LOG_QUEUE` | default queue | Queue name for shipping jobs |
| `CENTRAL_LOG_ENABLED` | `true` | Master switch (set `false` in testing) |

## What gets captured

| Situation | Shipped? |
|---|---|
| Uncaught exception (web, queue, Artisan, scheduler) | ✅ as error |
| `report($e)` inside a try/catch | ✅ as error |
| `Log::...()` on a shipped channel | ✅ as log entry |
| `Log::...()` on an unlisted channel | ❌ file only |
| Request to a matched `api/*` path | ✅ as API request |
| Exception silently swallowed without `report()` | ❌ |

## Testing

```bash
composer install
composer test
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
