<?php

return [
    /*
     * Master switch. Shipping only happens when this is true AND url + token are set.
     */
    'enabled' => env('CENTRAL_LOG_ENABLED', true),

    /*
     * Base API URL of your Log Central server, e.g. https://logs.example.com/api
     */
    'url' => env('CENTRAL_LOG_URL'),

    /*
     * The app's project key, from Log Central → Apps.
     */
    'token' => env('CENTRAL_LOG_TOKEN'),

    /*
     * The app slug registered on Log Central. Every entry must match it.
     * Defaults to a slug of the app name when left empty.
     */
    'app' => env('CENTRAL_LOG_APP'),

    /*
     * Which log channels to ship: a comma-separated list ("payment_callback,webhook"),
     * "*" for every channel defined in config/logging.php, or empty to ship none
     * (exceptions are still captured).
     */
    'channels' => env('CENTRAL_LOG_CHANNELS', '*'),

    /*
     * Request paths whose API traffic (method, route, status, duration,
     * response) is recorded to Log Central's API monitor. Comma-separated
     * wildcard patterns matched against the request path; empty disables
     * recording. Capture happens after the response is sent — it never adds
     * latency to requests.
     */
    'api_paths' => env('CENTRAL_LOG_API_PATHS', 'api/*'),

    /*
     * Which response bodies to include with recorded API requests:
     * "all", "failed" (status >= 400 only), or "none". Bodies are JSON-only,
     * scrubbed, and truncated to 4 KB.
     */
    'api_response' => env('CENTRAL_LOG_API_RESPONSE', 'all'),

    /*
     * Which request payloads (query + body input) to include: "all",
     * "failed", or "none". Scrubbed and truncated like responses; file
     * uploads are never included.
     */
    'api_payload' => env('CENTRAL_LOG_API_PAYLOAD', 'all'),

    /*
     * Queue name for the shipping jobs. Null uses the default queue.
     */
    'queue' => env('CENTRAL_LOG_QUEUE'),

    /*
     * Disable only for local/self-signed Log Central servers.
     */
    'verify_ssl' => env('CENTRAL_LOG_VERIFY_SSL', true),

    /*
     * Request input keys that are replaced with "[scrubbed]" before leaving this app.
     * Matched case-insensitively, and also as substrings ("card" matches card_number).
     */
    'scrub' => [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_key',
        'apikey',
        'secret',
        'authorization',
        'card',
        'cvv',
        'cvc',
        'pin',
    ],
];
