<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Payload\PayloadBuilder;
use Thijssensoftware\FlareClient\Payload\Sanitiser;

it('describes the exception, its chain and its frames', function (): void {
    $root = new PDOException('SQLSTATE[HY000] connection refused');
    $wrapper = new RuntimeException('Could not bill invoice 4821', 0, $root);

    $payload = app(PayloadBuilder::class)->build($wrapper, Source::Http);

    expect($payload['exception']['class'])->toBe(RuntimeException::class)
        ->and($payload['exception']['message'])->toBe('Could not bill invoice 4821')
        ->and($payload['previous'][0]['class'])->toBe(PDOException::class)
        ->and($payload['kind'])->toBe('php')
        ->and($payload['source'])->toBe('http')
        ->and($payload['exception']['frames'])->toBeArray()
        ->and($payload['event_id'])->toBeString();
});

it('stops walking a self-referential exception chain', function (): void {
    // Ten links is the guard; this proves it terminates rather than hanging.
    $deepest = new RuntimeException('root');
    $current = $deepest;

    foreach (range(1, 20) as $i) {
        $current = new RuntimeException('level '.$i, 0, $current);
    }

    $payload = app(PayloadBuilder::class)->build($current, Source::Http);

    expect(count($payload['previous']))->toBeLessThanOrEqual(10);
});

it('attaches source context to in-app frames only', function (): void {
    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Http);

    $frames = $payload['exception']['frames'];

    foreach ($frames as $frame) {
        if (($frame['in_app'] ?? false) === false) {
            expect($frame)->not->toHaveKey('context');
        }
    }

    expect($frames)->not->toBeEmpty();
});

it('reads the release sha the deploy script wrote', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('release.sha', "a1b2c3d4e5f6\n");

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Http);

    expect($payload['release_sha'])->toBe('a1b2c3d4e5f6');
});

it('omits the release when the deploy script has not written one', function (): void {
    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Http);

    expect($payload)->not->toHaveKey('release_sha');
});

it('carries the correlation id so the event can be joined to a bug report', function (): void {
    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Http);

    expect($payload['request_id'])->toBeString()->not->toBeEmpty();
});

it('scrubs request input, headers and the query string end to end', function (): void {
    // Source context is switched off for this one test. This file counts as
    // in-app, so the captured frames would otherwise include the literals
    // written just below and the assertions would be testing themselves.
    // (Worth knowing in its own right: source context captures code, so a
    // secret hardcoded near a throw site travels with the report.)
    config()->set('flare-client.frames.context_lines', 0);

    $sent = null;

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response([], 202);
    });

    Route::post('/pay', function (): void {
        throw new RuntimeException('Boom');
    });

    $this->post('/pay?token=resettoken123', [
        'card_number' => '4111111111111111',
        'nested' => ['password' => 'hunter2'],
        'amount' => 100,
    ], ['Authorization' => 'Bearer secret-token']);

    $encoded = json_encode($sent);

    expect($sent)->not->toBeNull()
        ->and($encoded)->not->toContain('4111111111111111')
        ->and($encoded)->not->toContain('hunter2')
        ->and($encoded)->not->toContain('resettoken123')
        ->and($encoded)->not->toContain('secret-token')
        ->and($sent['request']['input']['amount'])->toBe(100);
});

it('records the origin block for a non-http source', function (): void {
    $payload = app(PayloadBuilder::class)->build(
        new RuntimeException('Scheduled task failed'),
        Source::Schedule,
        ['command' => 'flare:prune', 'schedule_expression' => '20 3 * * *'],
    );

    expect($payload['origin']['command'])->toBe('flare:prune')
        ->and($payload['origin']['schedule_expression'])->toBe('20 3 * * *')
        ->and($payload['source'])->toBe('schedule');
});

it('omits an empty origin block', function (): void {
    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Http);

    expect($payload)->not->toHaveKey('origin');
});

it('scrubs a secret in the exception message itself', function (): void {
    $payload = app(PayloadBuilder::class)->build(
        new RuntimeException('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig'),
        Source::Http,
    );

    expect($payload['exception']['message'])->toBe(Sanitiser::REDACTED);
});

it('omits the request block when running in the console', function (): void {
    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Console);

    expect($payload)->not->toHaveKey('request');
});
