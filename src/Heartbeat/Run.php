<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Heartbeat;

/**
 * The budget of one flush: how many batches it may send and for how long.
 */
final class Run
{
    private int $batches = 0;

    public function __construct(
        private readonly float $startedAt,
        private readonly int $maxBatches,
        private readonly int $maxSeconds,
    ) {}

    public function batch(): void
    {
        $this->batches++;
    }

    public function spent(): bool
    {
        return $this->batches >= $this->maxBatches || microtime(true) - $this->startedAt > $this->maxSeconds;
    }
}
