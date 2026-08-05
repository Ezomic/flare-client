<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Payload;

use Illuminate\Support\Facades\Log;

/**
 * Keeps a payload inside the size flare will accept.
 *
 * flare answers anything over its cap with a 413, and a 413 is dropped rather
 * than spooled, because replaying an oversized payload cannot fix it. So an
 * event that is too big is an event nobody ever sees, and the events most
 * likely to be too big are wrapped exception chains with deep stacks, which
 * are exactly the ones worth reading.
 *
 * Detail is given up in the order a human misses it least, and the fingerprint
 * is protected throughout: the chain is never dropped, because flare groups on
 * the root cause, and enough frames are always kept for its signature to
 * survive. A payload that lost something says so.
 */
class SizeGuard
{
    /**
     * How many frames survive the first and second clamp.
     */
    private const CLAMP_STEPS = [10, 3];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function fit(array $payload): array
    {
        $max = $this->maxBytes();

        if ($this->size($payload) <= $max) {
            return $payload;
        }

        foreach ($this->reductions() as $reduce) {
            $payload = $reduce($payload);
            $payload['truncated'] = true;

            if ($this->size($payload) <= $max) {
                break;
            }
        }

        Log::debug('flare-client trimmed an oversized payload', ['bytes' => $this->size($payload)]);

        return $payload;
    }

    /**
     * @return array<int, callable(array<string, mixed>): array<string, mixed>>
     */
    private function reductions(): array
    {
        return [
            fn (array $payload): array => $this->stripContext($payload),
            fn (array $payload): array => $this->dropInput($payload),
            fn (array $payload): array => $this->clampFrames($payload, self::CLAMP_STEPS[0]),
            fn (array $payload): array => $this->clampFrames($payload, self::CLAMP_STEPS[1]),
        ];
    }

    /**
     * Source context is the first to go: it is the fattest thing in the
     * payload and the only part a stack trace is still readable without.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripContext(array $payload): array
    {
        return $this->overFrames($payload, function (array $frame): array {
            unset($frame['context']);

            return $frame;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dropInput(array $payload): array
    {
        if (! is_array($payload['request'] ?? null)) {
            return $payload;
        }

        $request = $payload['request'];

        if (isset($request['input'])) {
            $request['input'] = ['[dropped]' => true];
        }

        $payload['request'] = $request;

        return $payload;
    }

    /**
     * Never below the frames flare fingerprints on, or a trimmed event would
     * open a group of its own instead of joining the one it belongs to.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clampFrames(array $payload, int $keep): array
    {
        return $this->overExceptions($payload, function (array $exception) use ($keep): array {
            if (is_array($exception['frames'] ?? null)) {
                $exception['frames'] = array_slice(array_values($exception['frames']), 0, $keep);
            }

            return $exception;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function overFrames(array $payload, callable $callback): array
    {
        return $this->overExceptions($payload, function (array $exception) use ($callback): array {
            if (! is_array($exception['frames'] ?? null)) {
                return $exception;
            }

            $frames = [];

            foreach ($exception['frames'] as $frame) {
                $frames[] = is_array($frame) ? $callback($frame) : $frame;
            }

            $exception['frames'] = $frames;

            return $exception;
        });
    }

    /**
     * The thrown exception and every link of the chain behind it.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function overExceptions(array $payload, callable $callback): array
    {
        if (is_array($payload['exception'] ?? null)) {
            $payload['exception'] = $callback($payload['exception']);
        }

        if (! is_array($payload['previous'] ?? null)) {
            return $payload;
        }

        $chain = [];

        foreach ($payload['previous'] as $exception) {
            $chain[] = is_array($exception) ? $callback($exception) : $exception;
        }

        $payload['previous'] = $chain;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function size(array $payload): int
    {
        $encoded = json_encode($payload);

        return $encoded === false ? 0 : strlen($encoded);
    }

    private function maxBytes(): int
    {
        $value = config('flare-client.max_payload_bytes', 262144);

        return is_int($value) && $value > 0 ? $value : 262144;
    }
}
