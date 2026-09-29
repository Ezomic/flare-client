<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Payload\PayloadBuilder;
use Thijssensoftware\FlareClient\Payload\Sanitiser;
use Thijssensoftware\FlareClient\ProvidesFingerprint;
use Thijssensoftware\FlareClient\Reporter;

function groupedBy(?string $key, ?Throwable $previous = null): RuntimeException
{
    return new class($key, $previous) extends RuntimeException implements ProvidesFingerprint
    {
        public function __construct(private readonly ?string $key, ?Throwable $previous)
        {
            parent::__construct('Integration request failed', 0, $previous);
        }

        public function flareFingerprint(): ?string
        {
            return $this->key;
        }
    };
}

it('sends the grouping key an exception provides', function (): void {
    $payload = app(PayloadBuilder::class)->build(groupedBy('integration:stripe'), Source::Http);

    expect($payload['fingerprint'])->toBe('integration:stripe');
});

it('sends no grouping key for an exception that does not provide one', function (): void {
    $payload = app(PayloadBuilder::class)->build(new RuntimeException('Boom'), Source::Http);

    expect($payload)->not->toHaveKey('fingerprint');
});

it('leaves grouping to flare when the key is null or blank', function (?string $key): void {
    $payload = app(PayloadBuilder::class)->build(groupedBy($key), Source::Http);

    expect($payload)->not->toHaveKey('fingerprint');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('trims the key it sends', function (): void {
    $payload = app(PayloadBuilder::class)->build(groupedBy("  integration:mollie\n"), Source::Http);

    expect($payload['fingerprint'])->toBe('integration:mollie');
});

it('finds the key on an exception wrapped by one that has none', function (): void {
    // Laravel wraps anything thrown in a view in a ViewException, so the key
    // must survive a wrapper the app did not write.
    $wrapped = new RuntimeException('Rendering failed', 0, groupedBy('integration:stripe'));

    $payload = app(PayloadBuilder::class)->build($wrapped, Source::Http);

    expect($payload['fingerprint'])->toBe('integration:stripe');
});

it('prefers the outermost key when more than one link provides one', function (): void {
    $payload = app(PayloadBuilder::class)->build(
        groupedBy('checkout', groupedBy('integration:stripe')),
        Source::Http,
    );

    expect($payload['fingerprint'])->toBe('checkout');
});

it('skips a link whose key is blank and keeps walking the chain', function (): void {
    $payload = app(PayloadBuilder::class)->build(
        groupedBy('', groupedBy('integration:stripe')),
        Source::Http,
    );

    expect($payload['fingerprint'])->toBe('integration:stripe');
});

it('stops looking for a key after ten links', function (): void {
    $current = groupedBy('too-deep');

    foreach (range(1, 11) as $i) {
        $current = new RuntimeException('level '.$i, 0, $current);
    }

    $payload = app(PayloadBuilder::class)->build($current, Source::Http);

    expect($payload)->not->toHaveKey('fingerprint');
});

it('scrubs a key that looks like a secret', function (): void {
    $payload = app(PayloadBuilder::class)->build(
        groupedBy('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig'),
        Source::Http,
    );

    expect($payload['fingerprint'])->toBe(Sanitiser::REDACTED);
});

it('still reports the event when the key itself throws', function (): void {
    $broken = new class('Boom') extends RuntimeException implements ProvidesFingerprint
    {
        public function flareFingerprint(): ?string
        {
            throw new LogicException('the app got its own key wrong');
        }
    };

    $payload = app(PayloadBuilder::class)->build($broken, Source::Http);

    expect($payload)->not->toHaveKey('fingerprint')
        ->and($payload['exception']['message'])->toBe('Boom');
});

it('delivers the key to flare', function (): void {
    Http::fake(['*' => Http::response(['outcome' => 'stored'], 202)]);

    app(Reporter::class)->report(groupedBy('integration:stripe'));

    Http::assertSent(fn ($request): bool => $request['fingerprint'] === 'integration:stripe');
});
