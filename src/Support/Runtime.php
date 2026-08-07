<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Answers "are we serving an HTTP request right now".
 *
 * Deliberately not App::runningInConsole(), which only asks what the SAPI is:
 * under PHPUnit that is always cli even while a request is being simulated, so
 * it would mean no request is ever captured in a test and the scrubbing would
 * go unverified.
 *
 * argv is the discriminator. Laravel binds a Request even inside a queue worker
 * or an artisan command, where SetRequestForConsole synthesises one from
 * config('app.url') that carries a REQUEST_METHOD like any other, so that
 * header alone cannot tell a real request from an invented one. A web server
 * never passes argv, and a command always does.
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

        if (! $request instanceof Request || $request->server->has('argv')) {
            return null;
        }

        return $request->server->has('REQUEST_METHOD') ? $request : null;
    }
}
