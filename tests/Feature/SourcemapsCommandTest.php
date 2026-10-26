<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Thijssensoftware\FlareClient\FlareClientServiceProvider;

const SHA = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0';

beforeEach(function (): void {
    $this->build = sys_get_temp_dir().'/flare-maps-'.bin2hex(random_bytes(6));
    mkdir($this->build.'/assets', 0777, true);

    file_put_contents($this->build.'/assets/app-B7x2k1.js', 'console.log(1)');
    file_put_contents($this->build.'/assets/app-B7x2k1.js.map', '{"version":3,"mappings":"AAAA"}');
    file_put_contents($this->build.'/assets/vendor-Zz99aa.js.map', '{"version":3,"mappings":"BBBB"}');
    file_put_contents($this->build.'/manifest.json', '{}');

    config(['flare-client.url' => 'https://flare.test', 'flare-client.key' => 'flr_testkeytestkeytest']);
    Storage::disk('local')->put('release.sha', SHA."\n");
});

afterEach(function (): void {
    if (is_dir($this->build)) {
        exec('rm -rf '.escapeshellarg($this->build));
    }
});

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function run_sourcemaps(string $build, array $options = []): array
{
    $code = Artisan::call('flare:sourcemaps', ['--path' => $build] + $options);

    return [$code, Artisan::output()];
}

function maps_left(string $build): array
{
    return array_values(array_filter(
        array_map('basename', glob($build.'/assets/*') ?: []),
        fn (string $name): bool => str_ends_with($name, '.map'),
    ));
}

function fake_flare(int $status = 201): void
{
    Http::fake(['https://flare.test/*' => function (Request $request) use ($status) {
        $stored = collect($request->data())->pluck('filename')->filter()->count();

        return Http::response(['release' => SHA, 'files' => $stored, 'bytes' => 10], $status);
    }]);
}

it('uploads every map to the release and deletes them', function (): void {
    fake_flare();

    [$code, $output] = run_sourcemaps($this->build);

    expect($code)->toBe(0)
        ->and(maps_left($this->build))->toBe([])
        ->and(file_exists($this->build.'/assets/app-B7x2k1.js'))->toBeTrue()
        ->and(file_exists($this->build.'/manifest.json'))->toBeTrue()
        ->and($output)->toContain('Uploaded 2');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $names = collect($request->data())->pluck('filename')->filter()->sort()->values()->all();

        return $request->url() === 'https://flare.test/api/releases/'.SHA.'/sourcemaps'
            && $request->hasHeader('X-Flare-Key', 'flr_testkeytestkeytest')
            && $request->isMultipart()
            && $names === ['app-B7x2k1.js.map', 'vendor-Zz99aa.js.map'];
    });
});

it('sends the file contents byte for byte under the name maps[]', function (): void {
    fake_flare();

    run_sourcemaps($this->build);

    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data())->keyBy('filename');

        return $parts->every(fn (array $part): bool => $part['name'] === 'maps[]')
            && $parts['app-B7x2k1.js.map']['contents'] === '{"version":3,"mappings":"AAAA"}';
    });
});

it('does not fail when a map is already gone by the time it is deleted', function (): void {
    $build = $this->build;

    Http::fake(['*' => function () use ($build) {
        unlink($build.'/assets/vendor-Zz99aa.js.map');

        return Http::response(['files' => 2], 201);
    }]);

    [$code] = run_sourcemaps($this->build);

    expect($code)->toBe(0)
        ->and(maps_left($this->build))->toBe([]);
});

it('leaves the maps in place when asked to', function (): void {
    fake_flare();

    [$code] = run_sourcemaps($this->build, ['--keep' => true]);

    expect($code)->toBe(0)
        ->and(maps_left($this->build))->toHaveCount(2);
});

it('takes the release from the option before the release file', function (): void {
    fake_flare();

    run_sourcemaps($this->build, ['--release' => 'ABCDEF1234567']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/releases/abcdef1234567/sourcemaps'));
});

it('defaults the path to the build directory of the app', function (): void {
    fake_flare();

    $default = base_path('public/build');
    @mkdir($default.'/assets', 0777, true);
    file_put_contents($default.'/assets/x.js.map', '{"mappings":""}');

    try {
        $code = Artisan::call('flare:sourcemaps');

        expect($code)->toBe(0)
            ->and(file_exists($default.'/assets/x.js.map'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg(base_path('public')));
    }
});

it('finds maps in nested directories', function (): void {
    fake_flare();
    mkdir($this->build.'/assets/deep/er', 0777, true);
    file_put_contents($this->build.'/assets/deep/er/nested.js.map', '{"mappings":""}');

    [$code] = run_sourcemaps($this->build);

    expect($code)->toBe(0)
        ->and(glob($this->build.'/assets/deep/er/*'))->toBe([]);

    Http::assertSent(fn (Request $request): bool => collect($request->data())->pluck('filename')->contains('nested.js.map'));
});

it('does not touch files that merely look like maps', function (): void {
    fake_flare();
    file_put_contents($this->build.'/assets/notes.map.txt', 'keep');
    file_put_contents($this->build.'/assets/readme.map.md', 'keep');

    run_sourcemaps($this->build);

    expect(file_exists($this->build.'/assets/notes.map.txt'))->toBeTrue()
        ->and(file_exists($this->build.'/assets/readme.map.md'))->toBeTrue();
});

it('splits a large release over several requests', function (): void {
    fake_flare();
    config(['flare-client.sourcemaps.batch_bytes' => 40]);

    [$code, $output] = run_sourcemaps($this->build);

    expect($code)->toBe(0)
        ->and($output)->toContain('Uploaded 2');

    Http::assertSentCount(2);
});

it('never puts more files in a request than the server will keep', function (): void {
    fake_flare();
    config(['flare-client.sourcemaps.batch_files' => 1]);

    run_sourcemaps($this->build);

    Http::assertSentCount(2);
});

it('falls back to the default file count when the configured one is nonsense', function (): void {
    fake_flare();
    config(['flare-client.sourcemaps.batch_files' => 0]);

    run_sourcemaps($this->build);

    Http::assertSentCount(1);
});

it('sends a map larger than the batch size on its own', function (): void {
    fake_flare();
    config(['flare-client.sourcemaps.batch_bytes' => 1]);

    run_sourcemaps($this->build);

    Http::assertSentCount(2);
});

it('falls back to the default batch size when the configured one is nonsense', function (): void {
    fake_flare();
    config(['flare-client.sourcemaps.batch_bytes' => 0]);

    run_sourcemaps($this->build);

    Http::assertSentCount(1);
});

describe('failures', function (): void {
    it('fails when flare stored fewer maps than were sent', function (): void {
        Http::fake(['*' => Http::response(['files' => 1], 201)]);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('stored 1 of 2')
            ->and(maps_left($this->build))->toBe([]);
    });

    it('fails when flare does not say how many it stored', function (): void {
        Http::fake(['*' => Http::response([], 201)]);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('stored null of 2');
    });

    it('fails when the only maps are symlinks, and still removes the links', function (): void {
        fake_flare();
        unlink($this->build.'/assets/app-B7x2k1.js.map');
        unlink($this->build.'/assets/vendor-Zz99aa.js.map');
        $target = sys_get_temp_dir().'/flare-target-'.bin2hex(random_bytes(6)).'.js.map';
        file_put_contents($target, '{"mappings":"x"}');
        symlink($target, $this->build.'/assets/only.js.map');

        try {
            [$code, $output] = run_sourcemaps($this->build);

            expect($code)->toBe(1)
                ->and($output)->toContain('symbolic links')
                ->and(is_link($this->build.'/assets/only.js.map'))->toBeFalse()
                ->and(file_exists($target))->toBeTrue();

            Http::assertNothingSent();
        } finally {
            @unlink($target);
        }
    });

    it('deletes the maps even when the upload fails, so they are never left public', function (int $status): void {
        fake_flare($status);

        [$code] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and(maps_left($this->build))->toBe([]);
    })->with([401, 404, 413, 422, 429, 500, 503]);

    it('says what flare answered without printing the key', function (): void {
        Http::fake(['*' => Http::response(['message' => 'Invalid ingest key.'], 401)]);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('401')->toContain('Invalid ingest key.')
            ->and($output)->not->toContain('flr_testkeytestkeytest');
    });

    it('explains a 413 as a body limit on the web server', function (): void {
        fake_flare(413);

        [, $output] = run_sourcemaps($this->build);

        expect($output)->toContain('client_max_body_size');
    });

    it('survives an answer that is not json', function (): void {
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', 502)]);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('502');
    });

    it('survives a connection that fails', function (): void {
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('timed out')
            ->and(maps_left($this->build))->toBe([]);
    });

    it('stops at the first failing batch', function (): void {
        Http::fake(['*' => Http::response(['message' => 'no'], 500)]);
        config(['flare-client.sourcemaps.batch_bytes' => 1]);

        run_sourcemaps($this->build);

        Http::assertSentCount(1);
    });

    it('fails when there are no maps, and says how to get them', function (): void {
        fake_flare();
        unlink($this->build.'/assets/app-B7x2k1.js.map');
        unlink($this->build.'/assets/vendor-Zz99aa.js.map');

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('hidden');

        Http::assertNothingSent();
    });

    it('fails when the directory does not exist', function (): void {
        fake_flare();

        [$code, $output] = run_sourcemaps($this->build.'/missing');

        expect($code)->toBe(1)
            ->and($output)->toContain('missing');

        Http::assertNothingSent();
    });

    it('fails when two maps share a name, since flare stores them by name', function (): void {
        fake_flare();
        mkdir($this->build.'/assets/other', 0777, true);
        file_put_contents($this->build.'/assets/other/app-B7x2k1.js.map', '{"mappings":""}');

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('app-B7x2k1.js.map')
            ->and(maps_left($this->build))->toBe([]);

        Http::assertNothingSent();
    });

    it('fails without a key and still deletes the maps', function (): void {
        fake_flare();
        config(['flare-client.key' => null]);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('FLARE_KEY')
            ->and(maps_left($this->build))->toBe([]);

        Http::assertNothingSent();
    });

    it('fails without a url', function (): void {
        fake_flare();
        config(['flare-client.url' => '']);

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('FLARE_URL');
    });

    it('fails without a release', function (): void {
        fake_flare();
        Storage::disk('local')->delete('release.sha');

        [$code, $output] = run_sourcemaps($this->build);

        expect($code)->toBe(1)
            ->and($output)->toContain('release');

        Http::assertNothingSent();
    });

    it('fails when the release is not a hex sha', function (string $release): void {
        fake_flare();

        [$code] = run_sourcemaps($this->build, ['--release' => $release]);

        expect($code)->toBe(1);

        Http::assertNothingSent();
    })->with([
        'words' => ['latest'],
        'too short' => ['abc12'],
        'too long' => [str_repeat('a', 41)],
        'traversal' => ['../../etc'],
        'slash' => ['abc/12345'],
    ]);

    it('fails when the release file is empty or not a sha', function (string $content): void {
        fake_flare();
        Storage::disk('local')->put('release.sha', $content);

        [$code] = run_sourcemaps($this->build);

        expect($code)->toBe(1);
    })->with(['', "\n", 'main']);

    it('fails when a map cannot be deleted', function (): void {
        fake_flare();
        chmod($this->build.'/assets', 0555);

        try {
            [$code, $output] = run_sourcemaps($this->build);
        } finally {
            chmod($this->build.'/assets', 0777);
        }

        expect($code)->toBe(1)
            ->and($output)->toContain('Could not delete');
    })->skip(fn (): bool => function_exists('posix_getuid') && posix_getuid() === 0, 'root ignores directory permissions');
});

it('never reads outside the build directory through a symlinked directory', function (): void {
    fake_flare();
    $outside = sys_get_temp_dir().'/flare-outside-'.bin2hex(random_bytes(6));
    mkdir($outside);
    file_put_contents($outside.'/secret.js.map', '{"mappings":"x"}');
    symlink($outside, $this->build.'/assets/link');

    try {
        run_sourcemaps($this->build);

        expect(file_exists($outside.'/secret.js.map'))->toBeTrue();
        Http::assertSent(fn (Request $request): bool => ! collect($request->data())->pluck('filename')->contains('secret.js.map'));
    } finally {
        exec('rm -rf '.escapeshellarg($outside));
    }
});

it('refuses a map flare would not accept before sending any, and still removes them all', function (): void {
    fake_flare();
    file_put_contents($this->build.'/assets/Show[id]-Zz99aa.js.map', '{"version":3,"mappings":"CCCC"}');

    [$code, $output] = run_sourcemaps($this->build);

    expect($code)->toBe(1)
        ->and($output)->toContain('Show[id]-Zz99aa.js.map')
        ->and(maps_left($this->build))->toBe([]);

    Http::assertNothingSent();
});

it('refuses an empty map before sending any', function (): void {
    fake_flare();
    file_put_contents($this->build.'/assets/empty-Zz99aa.js.map', '');

    [$code, $output] = run_sourcemaps($this->build);

    expect($code)->toBe(1)
        ->and($output)->toContain('empty-Zz99aa.js.map');

    Http::assertNothingSent();
});

it('publishes the browser reporter as an asset', function (): void {
    $tag = collect(ServiceProvider::pathsToPublish(FlareClientServiceProvider::class, 'flare-client-browser'));

    expect($tag)->toHaveCount(1)
        ->and($tag->keys()->first())->toEndWith('resources/js/flare.js')
        ->and($tag->values()->first())->toEndWith('resources/js/vendor/flare.js')
        ->and(file_exists($tag->keys()->first()))->toBeTrue();
});

it('skips a symlinked map', function (): void {
    fake_flare();
    $outside = sys_get_temp_dir().'/flare-target-'.bin2hex(random_bytes(6)).'.js.map';
    file_put_contents($outside, '{"mappings":"x"}');
    symlink($outside, $this->build.'/assets/linked.js.map');

    try {
        run_sourcemaps($this->build);

        Http::assertSent(fn (Request $request): bool => ! collect($request->data())->pluck('filename')->contains('linked.js.map'));

        expect(is_link($this->build.'/assets/linked.js.map'))->toBeFalse()
            ->and(file_exists($outside))->toBeTrue();
    } finally {
        @unlink($outside);
    }
});
