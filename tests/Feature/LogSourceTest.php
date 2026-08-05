<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Thijssensoftware\FlareClient\LoggedMessage;
use Thijssensoftware\FlareClient\Support\Severity;

beforeEach(function (): void {
    config()->set('flare-client.sources.log', true);

    Http::fake(['*' => Http::response(['outcome' => 'stored'], 202)]);
});

it('reports a logged error that has no exception behind it', function (): void {
    Log::error('Payment webhook answered 200 with an empty body');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $payload['source'] === 'log'
            && $payload['level'] === 'error'
            && $payload['exception']['class'] === LoggedMessage::class
            && $payload['exception']['message'] === 'Payment webhook answered 200 with an empty body';
    });
});

it('points the stack at the line that logged it', function (): void {
    Log::error('from this file');

    Http::assertSent(function ($request): bool {
        $frames = $request->data()['exception']['frames'];

        return collect($frames)->contains(fn (array $frame): bool => str_contains(
            (string) ($frame['file'] ?? ''),
            'LogSourceTest.php',
        ));
    });
});

it('sends the level the record carried', function (): void {
    config()->set('flare-client.log_level', 'warning');

    Log::critical('disk nearly full');

    Http::assertSent(fn ($request): bool => $request->data()['level'] === 'critical');
});

it('says which channel and context the record came from', function (): void {
    Log::error('import skipped a file', ['file' => 'invoices-2026-08.csv']);

    Http::assertSent(function ($request): bool {
        $origin = $request->data()['origin'];

        return $origin['context']['file'] === 'invoices-2026-08.csv'
            && is_string($origin['channel']);
    });
});

it('ignores records below the threshold', function (): void {
    Log::warning('this is not worth an alert');
    Log::info('nor is this');

    Http::assertNothingSent();
});

it('sends nothing at all when the log source is switched off', function (): void {
    config()->set('flare-client.sources.log', false);

    Log::emergency('the building is on fire');

    Http::assertNothingSent();
});

it('leaves a record carrying an exception to the exception handler', function (): void {
    // Reporting it here as well would count the same failure twice and hide
    // the real stack behind the log call's.
    Log::error('billing failed', ['exception' => new RuntimeException('the real one')]);

    Http::assertNothingSent();
});

it('does not report its own failure to report', function (): void {
    config()->set('flare-client.log_level', 'debug');

    // The reporter writes a debug line when delivery fails. With the log
    // source on and the threshold at debug, that line is itself reportable,
    // which is a loop unless the re-entrancy guard covers this path.
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('flare is down');
    });

    Log::error('something broke');

    expect($attempts)->toBe(1);
});

it('refuses a level it does not recognise rather than reporting it', function (): void {
    expect(Severity::reaches('chatter', 'error'))->toBeFalse()
        ->and(Severity::reaches('error', 'chatter'))->toBeTrue()
        ->and(Severity::reaches('emergency', 'debug'))->toBeTrue();
});
