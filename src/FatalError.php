<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient;

use ErrorException;

/**
 * A fatal error, wearing a throwable's clothes.
 *
 * PHP does not throw these. The process simply stops: memory exhausted, time
 * limit reached, a file that would not compile. There is no exception object
 * and no stack to unwind, only the entry error_get_last() leaves behind.
 *
 * ErrorException is the right parent because its constructor takes the file
 * and line as arguments, which is the only way a throwable built after the
 * fact can report where the failure actually was rather than where it was
 * noticed.
 */
final class FatalError extends ErrorException {}
