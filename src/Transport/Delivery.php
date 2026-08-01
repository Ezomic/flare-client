<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Transport;

enum Delivery: string
{
    case Sent = 'sent';
    case Spooled = 'spooled';
    case Dropped = 'dropped';
    case Throttled = 'throttled';
    case Skipped = 'skipped';
}
