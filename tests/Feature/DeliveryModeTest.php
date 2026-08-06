<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Transport\CircuitBreaker;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Spool;

beforeEach(function (): void {
    config()->set('flare-client.delivery', 'spool');
});

/**
 * Http::fake() merges its stubs and the first match wins, so a test that needs
 * a different answer has to be the one that registers it.
 */
function fakeFlare(int $status = 202): void
{
    Http::fake(['*' => Http::response([], $status)]);
}

it('writes straight to the spool without posting anything', function (): void {
    fakeFlare();

    $delivery = app(Reporter::class)->report(new RuntimeException('boom'), Source::Console);

    expect($delivery)->toBe(Delivery::Spooled)
        ->and(app(Spool::class)->files())->toHaveCount(1);

    // The whole point: the report is a file write, not another request.
    Http::assertNothingSent();
});

it('does not consult the circuit breaker at all', function (): void {
    fakeFlare();

    config()->set('flare-client.circuit.failures', 1);

    app(CircuitBreaker::class)->recordFailure();

    expect(app(CircuitBreaker::class)->isOpen())->toBeTrue()
        ->and(app(Reporter::class)->report(new RuntimeException('boom'), Source::Console))
        ->toBe(Delivery::Spooled)
        ->and(app(Spool::class)->files())->toHaveCount(1);
});

it('drops the event when there is no spool to write to', function (): void {
    fakeFlare();

    // Nothing else would deliver it, so saying it was spooled would be a lie.
    config()->set('flare-client.spool.enabled', false);

    expect(app(Reporter::class)->report(new RuntimeException('boom'), Source::Console))
        ->toBe(Delivery::Dropped);
});

it('still sends the batch the flush is built on', function (): void {
    fakeFlare();

    // Only the inline path defers. Switching off the flush as well would mean
    // nothing is ever delivered.
    app(Spool::class)->push(['event_id' => 'one']);

    $this->artisan('flare:flush')->assertOk();

    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/api/ingest/batch'));
    expect(app(Spool::class)->files())->toBeEmpty();
});

it('reads a spooled test as a success rather than as flare being down', function (): void {
    fakeFlare();

    $this->artisan('flare:test')
        ->expectsOutputToContain('spool only')
        ->expectsOutputToContain('flare:flush')
        ->assertOk();
});

it('still posts inline by default', function (): void {
    fakeFlare();

    config()->set('flare-client.delivery', 'inline');

    expect(app(Reporter::class)->report(new RuntimeException('boom'), Source::Console))
        ->toBe(Delivery::Sent);

    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/api/ingest'));
});

it('reads an unreachable flare as a failure when delivery is inline', function (): void {
    config()->set('flare-client.delivery', 'inline');

    fakeFlare(503);

    $this->artisan('flare:test')
        ->expectsOutputToContain('Could not reach flare')
        ->assertFailed();
});
