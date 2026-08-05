<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Transport;

/**
 * What became of a batch, and how much of it.
 *
 * flare answers a batch with the number of events it actually took, which can
 * be fewer than were sent: it stops at the first event that hits the project
 * ceiling or a busy database and reports the count rather than failing the
 * whole batch. A plain "delivered" verdict throws that number away, and with
 * it every event past the one flare stopped at.
 */
final class BatchResult
{
    public function __construct(
        public readonly Delivery $delivery,
        public readonly int $accepted,
    ) {}
}
