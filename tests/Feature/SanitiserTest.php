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

describe('urls', function (): void {
    it('filters email and code query parameters and keeps the rest', function (): void {
        $url = $this->sanitiser->scrubUrl('https://billr.example/login?email=a%40b.nl&code=123456&page=2');

        expect($url)->not->toContain('b.nl')->not->toContain('123456')->toContain('page=2');
    });

    it('filters personal and nested query parameters', function (): void {
        $url = $this->sanitiser->scrubUrl('https://billr.example/x?user[phone]=0612345678&filter[status]=open');

        expect($url)->not->toContain('0612345678')->toContain('status');
    });

    it('filters the segment after a token cue', function (string $path): void {
        $url = $this->sanitiser->scrubUrl('https://billr.example'.$path);

        expect($url)->not->toContain('abc')->toContain('[filtered]');
    })->with([
        'reset' => ['/reset/abc'],
        'verify' => ['/email/verify/abc/more'],
        'invite' => ['/invite/abc'],
    ]);

    it('filters opaque and email shaped path segments', function (): void {
        $url = $this->sanitiser->scrubUrl('https://billr.example/links/Zk3j9QwErTy7UiOp2AsD/u/jan%40example.nl/show');

        expect($url)->not->toContain('Zk3j9QwErTy7UiOp2AsD')->not->toContain('example.nl')
            ->and($url)->toContain('/links/')->toContain('/show');
    });

    it('filters a long base64url segment', function (): void {
        $token = 'aB3_dE6-gH9jK2mN5pQ8sT1vW4yZ7bC0';

        expect($this->sanitiser->scrubUrl('https://billr.example/s/'.$token))->not->toContain($token);
    });

    it('keeps slugs, numbers, uuids and a reset page with no token', function (): void {
        $uuid = '0b0f3a6e-8f2d-4a6e-9a55-3f1c2d4e5f60';
        $path = '/invoices/42/'.$uuid.'/2024-annual-report-summary-final';

        expect($this->sanitiser->scrubUrl('https://billr.example:8443'.$path))->toBe('https://billr.example:8443'.$path)
            ->and($this->sanitiser->scrubUrl('https://billr.example/reset'))->toBe('https://billr.example/reset')
            ->and($this->sanitiser->scrubUrl('https://billr.example/'))->toBe('https://billr.example/');
    });

    it('scrubs a relative url without inventing a host', function (): void {
        expect($this->sanitiser->scrubUrl('/reset/abc?page=2'))->toBe('/reset/'.Sanitiser::REDACTED.'?page=2');
    });

    it('treats an unparseable url as text', function (): void {
        expect($this->sanitiser->scrubUrl('http:///x?email=a@b.nl'))->not->toContain('a@b.nl');
    });
});

describe('props', function (): void {
    it('filters personal values by key at any depth', function (): void {
        $props = $this->sanitiser->scrubProps([
            'auth' => ['user' => 'x'],
            'user' => ['id' => 4, 'name' => 'Jan', 'Email' => 'a@b.nl', 'phone_number' => '06', 'firstName' => 'J', 'tel' => '1'],
            'company' => ['name' => 'Acme', 'ip_address' => '10.0.0.1'],
            'rows' => [['mobile' => '06', 'qty' => 2]],
            'title' => 'Invoices',
        ]);

        expect($props['user'])->toBe([
            'id' => 4,
            'name' => '[filtered]',
            'Email' => '[filtered]',
            'phone_number' => '[filtered]',
            'firstName' => '[filtered]',
            'tel' => '[filtered]',
        ])
            ->and($props['company'])->toBe(['name' => '[filtered]', 'ip_address' => '[filtered]'])
            ->and($props['rows'])->toBe([['mobile' => '[filtered]', 'qty' => 2]])
            ->and($props['title'])->toBe('Invoices');
    });

    it('filters emails and tokens inside string values', function (): void {
        $props = $this->sanitiser->scrubProps([
            'note' => 'Mail jan@example.nl about it',
            'link' => 'https://x.example/a?token=abc123&b=1',
            'id' => 'Zk3j9QwErTy7UiOp2AsD',
            'flag' => true,
        ]);

        expect($props['note'])->toBe('Mail [filtered] about it')
            ->and($props['link'])->toBe('https://x.example/a?token=[filtered]&b=1')
            ->and($props['id'])->toBe('[filtered]')
            ->and($props['flag'])->toBeTrue();
    });
});

describe('messages', function (): void {
    it('filters assignments, bearer text and emails anywhere in the text', function (string $message, string $leaked): void {
        expect($this->sanitiser->scrubMessage($message))->not->toContain($leaked)->toContain('[filtered]');
    })->with([
        'token' => ['Request failed for /x?token=abc123 (500)', 'abc123'],
        'code' => ['bad code: 998877 given', '998877'],
        'api key' => ['api_key=sk12345 rejected', 'sk12345'],
        'json style' => ['{"password": "hunter2"}', 'hunter2'],
        'bearer' => ['401 with header Bearer abc.def-ghi', 'abc.def-ghi'],
        'email' => ['No account for jan@example.nl', 'jan@example.nl'],
    ]);

    it('leaves an ordinary message alone', function (): void {
        $message = "Cannot read properties of undefined (reading 'id')";

        expect($this->sanitiser->scrubMessage($message))->toBe($message);
    });
});
