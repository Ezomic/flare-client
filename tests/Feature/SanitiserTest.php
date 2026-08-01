<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Thijssensoftware\FlareClient\Payload\Sanitiser;

beforeEach(function (): void {
    $this->sanitiser = app(Sanitiser::class);
});

it('redacts by key name at any depth', function (): void {
    $clean = $this->sanitiser->scrubArray([
        'password' => 'hunter2',
        'nested' => ['deeper' => ['api_token' => 'abc123', 'safe' => 'keep me']],
        'card_number' => '4111111111111111',
        'title' => 'Invoice',
    ]);

    expect($clean['password'])->toBe(Sanitiser::REDACTED)
        ->and($clean['nested']['deeper']['api_token'])->toBe(Sanitiser::REDACTED)
        ->and($clean['nested']['deeper']['safe'])->toBe('keep me')
        ->and($clean['card_number'])->toBe(Sanitiser::REDACTED)
        ->and($clean['title'])->toBe('Invoice');
});

it('redacts by value shape whatever the key is called', function (string $value): void {
    $clean = $this->sanitiser->scrubArray(['data' => $value]);

    expect($clean['data'])->toBe(Sanitiser::REDACTED);
})->with([
    'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc'],
    'bearer' => ['Bearer sk-abcdefghijklmnop'],
    'stripe live key' => ['sk_live_abcdefghijklmnop'],
    'long hex' => ['a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'],
    'private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIE"],
    'card shaped' => ['4111 1111 1111 1111'],
]);

it('redacts denied headers and keeps the rest', function (): void {
    $clean = $this->sanitiser->scrubHeaders([
        'Authorization' => ['Bearer abc'],
        'Cookie' => ['session=1'],
        'X-Flare-Key' => ['flr_secret'],
        'Accept' => ['application/json'],
    ]);

    expect($clean['authorization'])->toBe(Sanitiser::REDACTED)
        ->and($clean['cookie'])->toBe(Sanitiser::REDACTED)
        ->and($clean['x-flare-key'])->toBe(Sanitiser::REDACTED)
        ->and($clean['accept'])->toBe(['application/json']);
});

it('scrubs the query string, which is where reset tokens live', function (): void {
    $url = $this->sanitiser->scrubUrl('https://billr.test/reset?token=abc123&email=a@b.com&page=2');

    expect($url)->toContain('token='.urlencode(Sanitiser::REDACTED))
        ->not->toContain('abc123')
        ->and($url)->toContain('page=2');
});

it('leaves a url without a query alone', function (): void {
    expect($this->sanitiser->scrubUrl('https://billr.test/invoices'))
        ->toBe('https://billr.test/invoices');
});

it('replaces an uploaded file with its metadata', function (): void {
    $clean = $this->sanitiser->scrubArray([
        'avatar' => UploadedFile::fake()->create('cv.pdf', 12),
    ]);

    expect($clean['avatar'])->toHaveKeys(['name', 'size', 'mime'])
        ->and($clean['avatar']['name'])->toBe('cv.pdf');
});

it('stops descending at the depth limit rather than recursing forever', function (): void {
    config()->set('flare-client.sanitise.max_depth', 3);

    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => 'too far']]]]];

    $clean = $this->sanitiser->scrubArray($deep);

    expect(json_encode($clean))->toContain('truncated')
        ->not->toContain('too far');
});

it('truncates long strings', function (): void {
    config()->set('flare-client.sanitise.max_string_length', 10);

    expect($this->sanitiser->scrubString(str_repeat('a', 100)))->toHaveLength(10);
});

it('honours extra keys an app adds', function (): void {
    config()->set('flare-client.sanitise.extra_keys', ['burgerservicenummer']);

    $clean = $this->sanitiser->scrubArray(['burgerservicenummer' => '123456789']);

    expect($clean['burgerservicenummer'])->toBe(Sanitiser::REDACTED);
});

it('turns an object into a marker rather than serialising it', function (): void {
    $clean = $this->sanitiser->scrubArray(['model' => new stdClass]);

    expect($clean['model'])->toBe('[object]');
});

it('keeps non-string scalars intact', function (): void {
    $clean = $this->sanitiser->scrubArray(['count' => 5, 'ok' => true, 'nothing' => null]);

    expect($clean['count'])->toBe(5)
        ->and($clean['ok'])->toBeTrue()
        ->and($clean['nothing'])->toBeNull();
});
