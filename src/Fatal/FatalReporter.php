<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Fatal;

use Closure;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\FatalError;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Support\Runtime;
use Throwable;

/**
 * Reports the failures that kill the process instead of raising an exception.
 *
 * The framework handler sees everything that is thrown. It never sees memory
 * running out, the time limit expiring, or a file that would not compile,
 * because there is nothing to catch: PHP writes the error and stops. On a 2 GB
 * box running nineteen apps, exhausted memory is not an exotic failure, and
 * until now it was the one failure flare could not see.
 */
class FatalReporter
{
    /**
     * The types that end the process. Warnings and notices are not here on
     * purpose: they are the log source's business, not this one's.
     */
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /**
     * Held so it can be given back.
     *
     * A handler that runs because memory ran out cannot allocate the payload
     * it needs to describe why. Releasing this at the top of the handler buys
     * back exactly enough room to build one.
     */
    private ?string $reserve = null;

    /**
     * The reporter is resolved when a fatal actually happens, not when this is
     * built. Resolving it up front would freeze the whole reporting graph at
     * boot, so an app rebinding any part of it afterwards would silently keep
     * talking to the objects that existed before it did.
     *
     * @param  Closure(): Reporter  $reporter
     */
    public function __construct(private readonly Closure $reporter) {}

    public function reserveMemory(int $bytes = 262144): void
    {
        // Once only. A provider that boots twice would otherwise hold back
        // twice the memory, which is the opposite of the point.
        if ($this->reserve !== null) {
            return;
        }

        $this->reserve = str_repeat(' ', $bytes);
    }

    /**
     * What the shutdown function calls. Split from handle() so the reading of
     * error_get_last() is the only part that cannot be exercised directly.
     */
    public function handleLast(): void
    {
        $this->handle(error_get_last());
    }

    /**
     * @param  array{type: int, message: string, file: string, line: int}|null  $error
     */
    public function handle(?array $error): void
    {
        $this->reserve = null;

        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }

        try {
            $reporter = ($this->reporter)();

            if ($this->alreadyReported($reporter, $error['message'])) {
                return;
            }

            // The process is ending, so there is no inline request to be had:
            // the socket, the timeout and often the memory to make one are all
            // gone. Appending a line to the spool is what is still possible,
            // and the flush delivers it from a process that is alive.
            config(['flare-client.delivery' => 'spool']);

            $reporter->report(
                new FatalError($error['message'], 0, $error['type'], $error['file'], $error['line']),
                Runtime::isHttpRequest() ? Source::Http : Source::Console,
                ['fatal_type' => $this->name($error['type'])],
            );
        } catch (Throwable) {
            // Running as the process dies, with no framework left to complain
            // to. Silence is the only thing left that cannot make it worse.
        }
    }

    /**
     * An uncaught exception ends the process as a fatal too, so it turns up
     * here after the handler has already reported it properly, with a real
     * stack. Reporting it again would file the same failure twice, the second
     * time with nothing useful in it.
     */
    private function alreadyReported(Reporter $reporter, string $message): bool
    {
        return str_starts_with($message, 'Uncaught ') && $reporter->hasReported();
    }

    private function name(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            default => 'E_USER_ERROR',
        };
    }
}
