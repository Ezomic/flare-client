<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Fatal;

use Illuminate\Contracts\Foundation\Application;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\FatalError;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Support\Runtime;
use Thijssensoftware\FlareClient\Transport\Spool;
use Throwable;

/**
 * Reports the failures that kill the process instead of raising an exception.
 *
 * The framework handler sees everything that is thrown. It never sees memory
 * running out, the time limit expiring, or a file that would not compile,
 * because there is nothing to catch: PHP writes the error and stops.
 *
 * This path builds its own payload rather than going through the reporter, and
 * that is the whole design. Measured on the production droplet, a report
 * through PayloadBuilder costs 2.3 MB: resolving the reporting graph, building
 * the payload, scrubbing it, sizing it. After memory exhaustion there is no
 * 2.3 MB, so the handler died inside itself and the failure it existed to
 * report was the one failure it could not. Holding that much back per worker,
 * on a 2 GB box running nineteen apps, is not a trade worth making either.
 *
 * So the fatal payload is assembled by hand, from values already in memory,
 * and handed straight to the spool.
 */
class FatalReporter
{
    /**
     * The types that end the process. Warnings and notices are not here on
     * purpose: they are the log source's business, not this one's.
     */
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /**
     * Held so it can be given back.
     *
     * Static because the thing reserved is the process's memory, not the
     * object's: one process booting the framework many times, which is what a
     * test suite is, would otherwise hold back another block per boot.
     */
    private static ?string $reserve = null;

    public function __construct(
        private readonly Application $app,
        private readonly Spool $spool,
    ) {}

    public function reserveMemory(int $bytes = 262144): void
    {
        // Once per process. A provider that boots twice would otherwise hold
        // back twice the memory, which is the opposite of the point.
        if (self::$reserve !== null) {
            return;
        }

        self::$reserve = str_repeat(' ', $bytes);
    }

    /**
     * What the shutdown function calls. Split from handle() so the reading of
     * error_get_last() is the only part that cannot be exercised directly.
     */
    public function handleLast(): void
    {
        $this->handle(error_get_last());
    }

    /**
     * @param  array{type: int, message: string, file: string, line: int}|null  $error
     */
    public function handle(?array $error): void
    {
        self::$reserve = null;

        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }

        try {
            if (! $this->enabled() || $this->alreadyReported($error['message'])) {
                return;
            }

            $this->spool->push($this->payload($error));
        } catch (Throwable) {
            // Running as the process dies, with no framework left to complain
            // to. Silence is the only thing left that cannot make it worse.
        }
    }

    /**
     * The same switches the reporter honours, read straight from config rather
     * than by resolving it. A fatal is attributed to the source the process was
     * serving, so an app that has switched that source off gets nothing here
     * either.
     */
    private function enabled(): bool
    {
        $key = config('flare-client.key');

        return config('flare-client.enabled') === true
            && is_string($key)
            && $key !== ''
            && config('flare-client.sources.'.$this->source()->value, true) === true;
    }

    /**
     * An uncaught exception ends the process as a fatal too, so it turns up
     * here after the handler has already reported it properly, with a real
     * stack. Reporting it again would file the same failure twice, the second
     * time with nothing useful in it.
     *
     * A reporter that was never resolved cannot have reported anything, which
     * is what makes this cheap: on the path that matters it never builds one.
     */
    private function alreadyReported(string $message): bool
    {
        if (! str_starts_with($message, 'Uncaught ')) {
            return false;
        }

        return $this->app->resolved(Reporter::class)
            && $this->app->make(Reporter::class)->hasReported();
    }

    /**
     * @param  array{type: int, message: string, file: string, line: int}  $error
     * @return array<string, mixed>
     */
    private function payload(array $error): array
    {
        $environment = config('flare-client.environment', 'production');

        return [
            'event_id' => $this->uuid(),
            'occurred_at' => date('c'),
            'kind' => 'php',
            'source' => $this->source()->value,
            'level' => 'error',
            'environment' => is_string($environment) ? $environment : 'production',
            'sdk' => ['name' => 'flare-client', 'version' => Reporter::VERSION],
            'exception' => [
                'class' => FatalError::class,
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                // A fatal has no stack, so its location is the only frame there
                // is. Without it every exhausted-memory failure in an app would
                // fingerprint identically whatever caused it. No source context
                // on purpose: reading a file is an allocation, and there is no
                // memory left to make one with.
                'frames' => [[
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'in_app' => ! str_contains($error['file'], '/vendor/'),
                ]],
            ],
            'context' => [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'memory_peak' => memory_get_peak_usage(true),
            ],
            'origin' => ['fatal_type' => $this->name($error['type'])],
        ];
    }

    /**
     * A v4 uuid without reaching for the framework's, which would mean loading
     * classes this process may never have touched, at the moment it has the
     * least room to load anything.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', array_map(
            fn (string $part): string => bin2hex($part),
            [substr($bytes, 0, 4), substr($bytes, 4, 2), substr($bytes, 6, 2), substr($bytes, 8, 2), substr($bytes, 10)],
        ));
    }

    private function source(): Source
    {
        return Runtime::isHttpRequest() ? Source::Http : Source::Console;
    }

    private function name(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            default => 'E_USER_ERROR',
        };
    }
}
