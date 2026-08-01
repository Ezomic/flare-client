<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Transport\CircuitBreaker;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Spool;

/**
 * The scenario that decides whether flare is safe to install in nineteen apps:
 * flare being down must cost the host app nothing but a spool write.
 */
it('spools rather than throwing when flare is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $delivery = app(Reporter::class)->report(new RuntimeException('Boom'));

    expect($delivery)->toBe(Delivery::Spooled)
        ->and(app(Spool::class)->files())->toHaveCount(1);
});

it('opens the circuit after repeated failures and stops making requests', function (): void {
    config()->set('flare-client.circuit.failures', 3);

    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('down');
    });

    $reporter = app(Reporter::class);

    foreach (range(1, 6) as $ignored) {
        $reporter->report(new RuntimeException('Boom'));
    }

    // Three attempts trip the breaker; the remaining three go straight to the
    // spool without paying the connection timeout. That difference is what
    // stops a flare outage becoming an estate-wide slowdown.
    expect($attempts)->toBe(3)
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});

it('recovers once flare answers again', function (): void {
    $breaker = app(CircuitBreaker::class);
    $breaker->recordFailure();

    Http::fake(['*' => Http::response(['id' => 'abc'], 202)]);

    app(Reporter::class)->report(new RuntimeException('Boom'));

    expect($breaker->isOpen())->toBeFalse();
});

it('drops rather than spools when flare says it is rate limiting', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Rate limited.'], 429, ['Retry-After' => '120'])]);

    $delivery = app(Reporter::class)->report(new RuntimeException('Boom'));

    // Spooling a 429 would replay exactly what flare asked us to stop sending.
    expect($delivery)->toBe(Delivery::Dropped)
        ->and(app(Spool::class)->files())->toBeEmpty()
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});

it('drops an oversized payload instead of spooling it forever', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Payload too large.'], 413)]);

    expect(app(Reporter::class)->report(new RuntimeException('Boom')))->toBe(Delivery::Dropped)
        ->and(app(Spool::class)->files())->toBeEmpty();
});

it('spools a server error so the event survives', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Busy'], 503)]);

    expect(app(Reporter::class)->report(new RuntimeException('Boom')))->toBe(Delivery::Spooled)
        ->and(app(Spool::class)->files())->toHaveCount(1);
});

it('never throws even when the payload builder fails', function (): void {
    Http::fake(['*' => Http::response([], 202)]);

    // A config value of the wrong shape is the realistic way this breaks.
    config()->set('flare-client.sanitise.headers', 'not an array');

    $delivery = app(Reporter::class)->report(new RuntimeException('Boom'));

    expect($delivery)->toBeInstanceOf(Delivery::class);
});

it('skips flow-control exceptions entirely', function (): void {
    Http::fake();

    $delivery = app(Reporter::class)->report(new NotFoundHttpException('no route'));

    expect($delivery)->toBe(Delivery::Skipped);

    Http::assertNothingSent();
});

it('merges an app ignore list with the defaults instead of replacing them', function (): void {
    config()->set('flare-client.extra_ignore_exceptions', [LogicException::class]);

    $reporter = app(Reporter::class);

    expect($reporter->shouldIgnore(new LogicException('mine')))->toBeTrue()
        ->and($reporter->shouldIgnore(new NotFoundHttpException('still ignored')))->toBeTrue()
        ->and($reporter->shouldIgnore(new RuntimeException('reported')))->toBeFalse();
});

it('sends nothing when no key is configured', function (): void {
    Http::fake();
    config()->set('flare-client.key', null);

    expect(app(Reporter::class)->report(new RuntimeException('Boom')))->toBe(Delivery::Skipped);

    Http::assertNothingSent();
});

it('sends nothing when a source is switched off', function (): void {
    Http::fake();
    config()->set('flare-client.sources.schedule', false);

    expect(app(Reporter::class)->report(new RuntimeException('Boom'), Source::Schedule))->toBe(Delivery::Skipped);

    Http::assertNothingSent();
});

it('is off entirely when disabled', function (): void {
    Http::fake();
    config()->set('flare-client.enabled', false);

    expect(app(Reporter::class)->report(new RuntimeException('Boom')))->toBe(Delivery::Skipped);

    Http::assertNothingSent();
});
