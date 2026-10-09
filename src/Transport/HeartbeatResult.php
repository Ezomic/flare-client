<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Transport;

/**
 * What became of a heartbeat delivery: finished with (delivered, or refused
 * for good), or to be tried again after the given number of seconds.
 */
final class HeartbeatResult
{
    private function __construct(
        public readonly bool $finished,
        public readonly int $backoff,
    ) {}

    public static function done(): self
    {
        return new self(true, 0);
    }

    public static function retry(int $backoff): self
    {
        return new self(false, $backoff);
    }
}
