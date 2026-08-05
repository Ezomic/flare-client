<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Payload\PayloadBuilder;
use Thijssensoftware\FlareClient\Payload\SizeGuard;

/**
 * An exception created far enough down this file's own stack to carry real
 * in-app frames, which is what source context hangs off and what makes a
 * payload big in the first place.
 */
function thrownDeep(int $depth, string $message = 'root cause'): Throwable
{
    if ($depth > 0) {
        return thrownDeep($depth - 1, $message);
    }

    return new RuntimeException($message);
}

/**
 * The shape this whole guard exists for: a wrapped chain, every link of which
 * used to carry its own fifty frames and eleven lines of context per frame.
 */
function deepChain(int $links = 10, int $depth = 25): Throwable
{
    $current = thrownDeep($depth);

    for ($i = 1; $i <= $links; $i++) {
        $current = new RuntimeException('wrapper '.$i.' '.str_repeat('x', 100), 0, $current);
    }

    return $current;
}

function payloadBytes(array $payload): int
{
    return strlen((string) json_encode($payload));
}

it('gives the chain no source context and a shorter stack', function (): void {
    $payload = app(PayloadBuilder::class)->build(deepChain(links: 2), Source::Console);

    $chainContext = [];

    foreach ($payload['previous'] as $exception) {
        foreach ($exception['frames'] as $frame) {
            $chainContext[] = $frame['context'] ?? null;
        }
    }

    expect(array_filter($chainContext))->toBeEmpty()
        ->and(count($payload['previous'][0]['frames']))->toBeLessThanOrEqual(15);
});

it('keeps source context on the exception that was actually thrown', function (): void {
    $payload = app(PayloadBuilder::class)->build(thrownDeep(3), Source::Console);

    $contexts = array_filter(array_map(
        fn (array $frame): mixed => $frame['context'] ?? null,
        $payload['exception']['frames'],
    ));

    expect($contexts)->not->toBeEmpty()
        ->and($payload)->not->toHaveKey('truncated');
});

it('brings an oversized payload under the cap flare will accept', function (): void {
    config()->set('flare-client.max_payload_bytes', 16384);

    $payload = app(PayloadBuilder::class)->build(deepChain(), Source::Console);

    expect(payloadBytes($payload))->toBeLessThanOrEqual(16384)
        ->and($payload['truncated'])->toBeTrue();
});

it('gives up source context before anything else', function (): void {
    $full = app(PayloadBuilder::class)->build(thrownDeep(20), Source::Console);

    config()->set('flare-client.max_payload_bytes', payloadBytes($full) - 100);

    $trimmed = app(PayloadBuilder::class)->build(thrownDeep(20), Source::Console);

    $contexts = array_filter(array_map(
        fn (array $frame): mixed => $frame['context'] ?? null,
        $trimmed['exception']['frames'],
    ));

    expect($contexts)->toBeEmpty()
        // The frames themselves survive: they are what makes a trace readable
        // and what flare fingerprints on.
        ->and(count($trimmed['exception']['frames']))->toBe(count($full['exception']['frames']));
});

it('drops request input rather than the stack', function (): void {
    Route::post('/orders', fn () => abort(500))->middleware('web');

    $this->app['request'] = Request::create('/orders', 'POST', [
        'notes' => str_repeat('n', 20000),
    ], server: ['REQUEST_METHOD' => 'POST']);

    $builder = app(PayloadBuilder::class);

    config()->set('flare-client.max_payload_bytes', 6000);

    $payload = $builder->build(thrownDeep(5), Source::Http);

    expect($payload['request']['input'])->toBe(['[dropped]' => true])
        ->and($payload['exception']['frames'])->not->toBeEmpty();
});

it('keeps enough frames for flare to still group the event', function (): void {
    // The last resort clamp. Below three in-app frames flare would fingerprint
    // the trimmed event differently and open a group of its own for it.
    config()->set('flare-client.max_payload_bytes', 1);

    $payload = app(PayloadBuilder::class)->build(deepChain(), Source::Console);

    expect(count($payload['exception']['frames']))->toBe(3)
        ->and(count($payload['previous'][0]['frames']))->toBe(3)
        ->and($payload['previous'])->toHaveCount(10)
        ->and($payload['exception']['message'])->toContain('wrapper 10')
        ->and($payload['truncated'])->toBeTrue();
});

it('leaves a payload it cannot measure alone', function (): void {
    $guard = app(SizeGuard::class);

    // json_encode fails on invalid UTF-8, and a payload that cannot be sized
    // must not be mangled on the way out.
    expect($guard->fit(['exception' => ['message' => "\xB1\x31"]]))
        ->toBe(['exception' => ['message' => "\xB1\x31"]]);
});
