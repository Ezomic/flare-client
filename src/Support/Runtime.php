<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Answers "are we serving an HTTP request right now".
 *
 * Deliberately not App::runningInConsole(), which only asks what the SAPI is.
 * Laravel binds a Request even inside a queue worker or an artisan command,
 * where it is synthesised from the CLI and describes nothing; and under
 * PHPUnit the SAPI is always cli even while a request is being simulated, so
 * runningInConsole() would mean no request is ever captured in a test and the
 * scrubbing would go unverified.
 *
 * REQUEST_METHOD is the discriminator: the web server sets it, and a request
 * synthesised from argv does not have it.
 */
final class Runtime
{
    public static function isHttpRequest(): bool
    {
        return self::httpRequest() instanceof Request;
    }

    /**
     * The request being served, or null when there is not one.
     *
     * Returned rather than merely reported so a caller that needs the request
     * does not have to make the same checks again and handle a "cannot happen"
     * branch that only exists because the answer was thrown away.
     */
    public static function httpRequest(): ?Request
    {
        if (! App::has('request')) {
            return null;
        }

        $request = App::get('request');

        return $request instanceof Request && $request->server->has('REQUEST_METHOD')
            ? $request
            : null;
    }
}
