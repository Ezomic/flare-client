<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient;

use RuntimeException;

/**
 * A log record that was never an exception.
 *
 * flare's payload is built around a throwable, and a Log::error() has no
 * throwable behind it. Constructing one here captures the stack at the point
 * of the log call, which is exactly the frame a reader wants, and gives the
 * record a class flare can fingerprint on so every occurrence of the same
 * message lands in one group.
 */
final class LoggedMessage extends RuntimeException {}
