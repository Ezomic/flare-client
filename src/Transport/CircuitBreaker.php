<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Transport;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Stops talking to flare after repeated failures.
 *
 * This is the single most important resilience detail in the package. Without
 * it, flare being down during its own deploy would add the full request
 * timeout to every request in every instrumented app simultaneously, turning
 * an error tracker outage into an estate-wide slowdown.
 *
 * State lives in the cache rather than a file so it is shared across the
 * process pool: one worker discovering flare is down should spare the others
 * from finding out the hard way.
 */
class CircuitBreaker
{
    private const FAILURES_KEY = 'flare-client:failures';

    private const OPEN_KEY = 'flare-client:open-until';

    public function isOpen(): bool
    {
        try {
            $until = Cache::get(self::OPEN_KEY);
        } catch (Throwable) {
            return false;
        }

        return is_int($until) && $until > time();
    }

    public function recordSuccess(): void
    {
        $this->forget(self::FAILURES_KEY);
        $this->forget(self::OPEN_KEY);
    }

    public function recordFailure(): void
    {
        try {
            $failures = Cache::get(self::FAILURES_KEY);
            $count = (is_int($failures) ? $failures : 0) + 1;

            Cache::put(self::FAILURES_KEY, $count, $this->cooldown() * 2);

            if ($count >= $this->threshold()) {
                Cache::put(self::OPEN_KEY, time() + $this->cooldown(), $this->cooldown());
            }
        } catch (Throwable) {
            // A cache that is itself broken must not break reporting.
        }
    }

    /**
     * A 429 is flare deliberately shedding load, not flare being broken, so it
     * mutes rather than trips the breaker and the events are dropped rather
     * than spooled for replay.
     */
    public function muteFor(int $seconds): void
    {
        try {
            Cache::put(self::OPEN_KEY, time() + max($seconds, 1), max($seconds, 1));
        } catch (Throwable) {
            // Nothing to do.
        }
    }

    private function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // Nothing to do.
        }
    }

    private function threshold(): int
    {
        $value = config('flare-client.circuit.failures', 3);

        return is_int($value) && $value > 0 ? $value : 3;
    }

    private function cooldown(): int
    {
        $value = config('flare-client.circuit.cooldown', 60);

        return is_int($value) && $value > 0 ? $value : 60;
    }
}
