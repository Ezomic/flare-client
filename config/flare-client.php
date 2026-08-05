<?php

declare(strict_types=1);
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    'enabled' => (bool) env('FLARE_ENABLED', true),

    'url' => env('FLARE_URL', 'https://flare.thijssensoftware.nl'),

    'key' => env('FLARE_KEY'),

    'environment' => env('FLARE_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Short on purpose. This runs inline in the request that threw, so the cost
    | of flare being slow is paid by the user waiting for an error page.
    |
    */

    'connect_timeout' => (float) env('FLARE_CONNECT_TIMEOUT', 0.5),
    'timeout' => (float) env('FLARE_TIMEOUT', 1.5),

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | 'log' is off by default because it can flood: a single misbehaving loop
    | writing Log::error() would fill both the spool and flare's retention.
    |
    */

    'sources' => [
        'http' => (bool) env('FLARE_SOURCE_HTTP', true),
        'job' => (bool) env('FLARE_SOURCE_JOB', true),
        'schedule' => (bool) env('FLARE_SOURCE_SCHEDULE', true),
        'console' => (bool) env('FLARE_SOURCE_CONSOLE', true),
        'log' => (bool) env('FLARE_SOURCE_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored exceptions
    |--------------------------------------------------------------------------
    |
    | Flow control, not bugs. Reporting these is most of the noise an error
    | tracker can generate, and filtering here means the traffic is never sent
    | at all rather than counted and discarded at the far end.
    |
    | Apps add to this list. They cannot shorten it by accident, because the
    | defaults are merged in rather than replaced.
    |
    */

    'ignore_exceptions' => [
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        TokenMismatchException::class,
        ThrottleRequestsException::class,
        ModelNotFoundException::class,
    ],

    'extra_ignore_exceptions' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanitising
    |--------------------------------------------------------------------------
    |
    | Three redundant layers, because the cost of a miss is a secret sitting in
    | an error tracker forever. Header names, key names at any depth, and the
    | shape of the value itself regardless of what it is called.
    |
    */

    'sanitise' => [
        'headers' => [
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-flare-key',
            'x-xsrf-token',
            'php-auth-user',
            'php-auth-pw',
        ],

        'key_pattern' => '/(pass|secret|token|key|auth|credit|card|cvv|iban|bsn|ssn|otp|pin|signature|session)/i',

        'extra_keys' => [],

        'send_user' => (bool) env('FLARE_SEND_USER', true),
        'send_pii' => (bool) env('FLARE_SEND_PII', false),

        'max_body_bytes' => (int) env('FLARE_MAX_BODY_BYTES', 8192),
        'max_string_length' => (int) env('FLARE_MAX_STRING', 2000),
        'max_depth' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stack traces
    |--------------------------------------------------------------------------
    */

    'frames' => [
        'limit' => (int) env('FLARE_FRAME_LIMIT', 50),

        // Every link of a wrapped chain carries its own stack, so the chain is
        // held to a fraction of the thrown exception's and never carries
        // source context. Enough is kept for flare to fingerprint the root
        // cause, which is what it groups on.
        'chain_limit' => (int) env('FLARE_CHAIN_FRAME_LIMIT', 15),
        'context_lines' => (int) env('FLARE_CONTEXT_LINES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload size
    |--------------------------------------------------------------------------
    |
    | flare refuses anything larger with a 413, and a 413 is dropped rather
    | than spooled because replaying it cannot help. Detail is given up before
    | that happens, in the order a human misses it least.
    |
    */

    'max_payload_bytes' => (int) env('FLARE_MAX_PAYLOAD_BYTES', 262144),

    /*
    |--------------------------------------------------------------------------
    | Spool
    |--------------------------------------------------------------------------
    |
    | The caps are not tuning knobs, they are a safety belt. A spool that fills
    | the droplet would take down all nineteen apps, which would be a
    | spectacular own goal for an error tracker.
    |
    */

    'spool' => [
        'enabled' => (bool) env('FLARE_SPOOL', true),
        'path' => env('FLARE_SPOOL_PATH', 'flare-spool'),
        'max_file_bytes' => (int) env('FLARE_SPOOL_MAX_FILE', 5 * 1024 * 1024),
        'max_total_bytes' => (int) env('FLARE_SPOOL_MAX_TOTAL', 20 * 1024 * 1024),
        'batch_size' => (int) env('FLARE_SPOOL_BATCH', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Without this, flare being down during its own deploy would add the full
    | timeout to every request in every instrumented app at the same time.
    |
    */

    'circuit' => [
        'failures' => (int) env('FLARE_CIRCUIT_FAILURES', 3),
        'cooldown' => (int) env('FLARE_CIRCUIT_COOLDOWN', 60),
    ],

    'release_file' => env('FLARE_RELEASE_FILE', 'release.sha'),

];
