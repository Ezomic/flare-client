<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient;

use Illuminate\Support\Facades\Log;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Payload\PayloadBuilder;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Transport;
use Throwable;

/**
 * The one entry point.
 *
 * Its overriding obligation is negative: an error tracker that breaks the app
 * it monitors is worse than no error tracker. Every path is wrapped, every
 * failure is swallowed to the host app's own log, and a throwable raised while
 * reporting is never itself reported.
 */
class Reporter
{
    /**
     * Reported in every payload, so flare can tell which apps are behind.
     */
    public const VERSION = '0.3.1';

    private bool $reporting = false;

    private bool $reported = false;

    public function __construct(
        private readonly PayloadBuilder $payloads,
        private readonly Transport $transport,
    ) {}

    /**
     * @param  array<string, mixed>  $origin
     */
    public function report(
        Throwable $e,
        Source $source = Source::Http,
        array $origin = [],
        string $level = 'error',
    ): Delivery {
        // Re-entrancy guard. Without it, a reporter that throws while building
        // a payload would be reported, throw again, and recurse until the
        // process died: an error tracker taking down the app it watches.
        if ($this->reporting) {
            return Delivery::Skipped;
        }

        try {
            $this->reporting = true;

            if (! $this->enabled($source) || $this->shouldIgnore($e)) {
                return Delivery::Skipped;
            }

            $this->reported = true;

            return $this->transport->send($this->payloads->build($e, $source, $origin, $level));
        } catch (Throwable $failure) {
            $this->swallow($failure);

            return Delivery::Skipped;
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * Whether anything has been handed to the transport in this process.
     *
     * The fatal handler needs this: an uncaught exception ends the process as
     * a fatal as well, and reporting it a second time would file the same
     * failure twice, the second time without its stack.
     */
    public function hasReported(): bool
    {
        return $this->reported;
    }

    public function shouldIgnore(Throwable $e): bool
    {
        foreach ($this->ignored() as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Defaults and app additions are merged, never replaced. An app extending
     * the list cannot accidentally shorten it, which is what would happen if
     * publishing the config meant overwriting the defaults.
     *
     * @return array<int, class-string<Throwable>>
     */
    public function ignored(): array
    {
        $defaults = config('flare-client.ignore_exceptions', []);
        $extra = config('flare-client.extra_ignore_exceptions', []);

        $merged = array_merge(
            is_array($defaults) ? $defaults : [],
            is_array($extra) ? $extra : [],
        );

        $classes = [];

        foreach ($merged as $class) {
            if (is_string($class) && $class !== '') {
                /** @var class-string<Throwable> $class */
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function enabled(Source $source): bool
    {
        if (config('flare-client.enabled', true) !== true) {
            return false;
        }

        if (! is_string(config('flare-client.key')) || config('flare-client.key') === '') {
            return false;
        }

        return config('flare-client.sources.'.$source->value, true) === true;
    }

    private function swallow(Throwable $e): void
    {
        try {
            Log::debug('flare-client failed to report an exception', [
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // If even logging fails there is genuinely nothing left to do, and
            // doing nothing is still better than propagating.
        }
    }
}
