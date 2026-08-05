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
     * Enough frames to still read the trace.
     */
    private const READABLE_FRAMES = 10;

    /**
     * The floor. flare fingerprints on the top in-app frames, so clamping
     * below this would regroup the event rather than shrink it.
     */
    private const FINGERPRINT_FRAMES = 3;

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

        $payload = $this->stripContext($payload);

        if ($this->size($payload) > $max) {
            $payload = $this->dropInput($payload);
        }

        if ($this->size($payload) > $max) {
            $payload = $this->clampFrames($payload, self::READABLE_FRAMES);
        }

        if ($this->size($payload) > $max) {
            $payload = $this->clampFrames($payload, self::FINGERPRINT_FRAMES);
        }

        $payload['truncated'] = true;

        Log::debug('flare-client trimmed an oversized payload', ['bytes' => $this->size($payload)]);

        return $payload;
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

        $request = $this->object($payload['request']);

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
                $frames[] = $callback($this->object($frame));
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
            $payload['exception'] = $callback($this->object($payload['exception']));
        }

        if (! is_array($payload['previous'] ?? null)) {
            return $payload;
        }

        $chain = [];

        foreach ($payload['previous'] as $exception) {
            $chain[] = $callback($this->object($exception));
        }

        $payload['previous'] = $chain;

        return $payload;
    }

    /**
     * Narrows a decoded value to the object shape the payload is built from.
     *
     * Every part of a payload is a JSON object, whose keys are strings by
     * definition, but nothing in the type system says so once a value has been
     * read back out of one.
     *
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        $object = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
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
