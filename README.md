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

- PHP 7.4 or higher
- Laravel 6, 7, 8, 9, 10, 11, 12, or 13
- `guzzlehttp/guzzle` on Laravel 6 only, which ships no HTTP client of its own
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

On Laravel 6, also install an HTTP client, which the framework does not provide until 7.0:

```bash
composer require guzzlehttp/guzzle
```

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
| `CENTRAL_LOG_CHANNELS` | `*` | Channels to ship; `*` = all (exceptions ship regardless) |
| `CENTRAL_LOG_API_PATHS` | `api/*` | Paths whose API traffic is recorded; empty disables monitoring |
| `CENTRAL_LOG_API_RESPONSE` | `all` | Response bodies to include: `all`, `failed` (4xx/5xx only), or `none` |
| `CENTRAL_LOG_API_PAYLOAD` | `all` | Request payloads to include: `all`, `failed` (4xx/5xx only), or `none` |
| `CENTRAL_LOG_QUEUE` | default queue | Queue name for shipping jobs |
| `CENTRAL_LOG_VERIFY_SSL` | `true` | Set `false` only for a local or self-signed Log Central server |
| `CENTRAL_LOG_ENABLED` | `true` | Master switch (set `false` in testing) |

Shipping only happens when `CENTRAL_LOG_ENABLED` is true **and** both the URL and token are set. With anything missing the package stays completely dormant — no middleware, no listeners, no channel changes.

## How it works

- **Log channels are wrapped, not replaced.** Each nominated channel is renamed to `<name>_local` and a stack of `[<name>_local, central]` takes its place, so your existing files, Slack channels, or Papertrail sinks keep working exactly as before. The `central` and `emergency` channels are never wrapped, and neither are channels that are already stacks. Under `*`, discard sinks (`null` and `NullHandler`-backed channels) are skipped too — they exist to throw entries away, so the wildcard must not resurrect them.
- **Entries are buffered, then batched.** Log rows and API records accumulate in memory (up to 200) and flush as a single queued job when the app terminates or the process shuts down — one job per batch, not one per entry.
- **API requests are recorded during `terminate()`**, after the response has been sent, so nothing here adds latency to the request itself.
- **Delivery is retried, then given up quietly.** A transient failure (connection error, 5xx, 408, 429) is retried up to 3 times with a 10s then 60s backoff, and dropped in silence if it never succeeds — telemetry must never disturb the host app. Anything else (a bad URL, a rejected token) cannot be fixed by retrying, so the job is failed loudly and lands in `failed_jobs` where you will see it.
- **The shipper never reports itself.** Exceptions thrown inside the package are filtered out before dispatch, so a broken Log Central can't loop telemetry back into itself.
- **Oversized fields are capped.** Any single field over 256 KB is replaced with a marker plus a 16 KB preview; API response and payload bodies are truncated to 4 KB.

## What gets captured

| Situation | Shipped? |
|---|---|
| Uncaught exception (web, queue, Artisan, scheduler) | ✅ as error |
| `report($e)` inside a try/catch | ✅ as error |
| `Log::...()` on a shipped channel | ✅ as log entry |
| `Log::...()` on an unlisted channel | ❌ file only |
| Request to a matched `api/*` path | ✅ as API request |
| Exception silently swallowed without `report()` | ❌ |

## Scrubbing sensitive data

Request input, log context, API payloads, and API responses are all filtered before anything leaves the application. Keys are matched case-insensitively **and as substrings**, so `card` also covers `card_number` and `CardHolder`.

Defaults: `password`, `password_confirmation`, `current_password`, `token`, `api_key`, `apikey`, `secret`, `authorization`, `card`, `cvv`, `cvc`, `pin`.

Publish the config and edit the `scrub` array to add your own — replacing the list, so keep the defaults you still want:

```php
'scrub' => [
    'password',
    'token',
    'ic_number',
    'bank_account',
],
```

File uploads are never included in a payload.

## Verifying the integration

```bash
# 1. a worker must be consuming the queue you configured
php artisan queue:work --queue=logs

# 2. send a test error
php artisan tinker --execute 'report(new RuntimeException("log central smoke test"));'
```

The entry should appear on the Log Central dashboard within a few seconds. If it does not, work through the table below.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Nothing arrives at all | No worker is consuming the queue. If you set `CENTRAL_LOG_QUEUE=logs`, the worker must run with `--queue=logs`, otherwise the jobs sit unprocessed. |
| Nothing arrives, queue is empty | The package is dormant: `CENTRAL_LOG_ENABLED` is false, or the URL or token is missing. |
| Jobs land in `failed_jobs` | Deliberate. The server rejected the request in a way retrying cannot fix — usually a wrong project key, an app slug not registered on Log Central, or a URL missing its `/api` suffix. Read the exception on the failed job. |
| Every entry appears twice | Fixed in v1.2.1. Upgrade, then run `php artisan config:clear` (or re-run `config:cache`) to rebuild config cached while the old version was wrapping channels non-idempotently. |
| Exceptions ship but log entries do not | `CENTRAL_LOG_CHANNELS` does not include the channel being written to. Exceptions ship regardless of this setting; log entries do not. |
| SSL certificate errors | A local or self-signed Log Central server — set `CENTRAL_LOG_VERIFY_SSL=false`. Never do this against production. |
| `Class "GuzzleHttp\Client" not found` | Laravel 6 without an HTTP client: `composer require guzzlehttp/guzzle`. |
| Telemetry noise in your test suite | Set `CENTRAL_LOG_ENABLED=false` in `phpunit.xml` / `.env.testing`. |

## Compatibility

| Laravel | PHP | Notes |
|---|---|---|
| 6 | 7.4+ | Requires `guzzlehttp/guzzle`; the framework has no HTTP client |
| 7 – 8 | 7.4+ | |
| 9 – 13 | 8.0+ | Follows each release's own PHP floor |

The ingest contract is frozen at v1.0 — any package version talks to any Log Central server.

## Testing

```bash
composer install
composer test
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
