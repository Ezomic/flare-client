<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Thijssensoftware\FlareClient\Fatal\FatalReporter;
use Thijssensoftware\FlareClient\FatalError;
use Thijssensoftware\FlareClient\FlareClientServiceProvider;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Transport\Spool;

/**
 * The failures PHP does not throw. There is no way to cause a real one inside
 * a test without ending the test run, so the handler takes the error array as
 * an argument and the tests hand it exactly what error_get_last() would.
 */
function fatal(array $overrides = []): array
{
    return array_replace([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
        'file' => '/home/deploy/billr/app/Actions/ExportLedger.php',
        'line' => 88,
    ], $overrides);
}

beforeEach(function (): void {
    Http::fake(['*' => Http::response([], 202)]);
});

it('reports a fatal that no exception handler could have seen', function (): void {
    app(FatalReporter::class)->handle(fatal());

    $events = app(Spool::class)->read(app(Spool::class)->files()[0]);
    $payload = $events[0];

    expect($payload['exception']['class'])->toBe(FatalError::class)
        ->and($payload['exception']['message'])->toContain('Allowed memory size')
        ->and($payload['exception']['file'])->toBe('/home/deploy/billr/app/Actions/ExportLedger.php')
        ->and($payload['exception']['line'])->toBe(88)
        ->and($payload['origin']['fatal_type'])->toBe('E_ERROR');
});

it('reports where the process stopped, so two of these are two bugs', function (): void {
    // A fatal has no stack. Without a frame carrying its location, every
    // exhausted-memory failure in an app would fingerprint identically and
    // land in one group whatever caused it.
    app(FatalReporter::class)->handle(fatal());

    $payload = app(Spool::class)->read(app(Spool::class)->files()[0])[0];
    $frames = $payload['exception']['frames'];

    expect($frames)->toHaveCount(1)
        ->and($frames[0]['file'])->toBe('/home/deploy/billr/app/Actions/ExportLedger.php')
        ->and($frames[0]['line'])->toBe(88)
        ->and($frames[0]['in_app'])->toBeTrue();
});

it('reads source context for a fatal in a file it can open', function (): void {
    $path = realpath(sys_get_temp_dir()).'/flare-fatal-'.getmypid().'.php';
    file_put_contents($path, "<?php\n// one\n// two\n// three\n");

    app(FatalReporter::class)->handle(fatal(['file' => $path, 'line' => 3]));

    unlink($path);

    $frame = app(Spool::class)->read(app(Spool::class)->files()[0])[0]['exception']['frames'][0];

    expect($frame['context']['lines'])->toContain('// three');
});

it('goes through the spool whatever the delivery mode says', function (): void {
    // A dying process has no socket, no timeout budget and often no memory to
    // make a request with. Appending to a file is what is still possible.
    config()->set('flare-client.delivery', 'inline');

    app(FatalReporter::class)->handle(fatal());

    expect(app(Spool::class)->files())->toHaveCount(1);
    Http::assertNothingSent();
});

it('ignores the warnings and notices that did not end anything', function (): void {
    foreach ([E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING] as $type) {
        app(FatalReporter::class)->handle(fatal(['type' => $type]));
    }

    expect(app(Spool::class)->files())->toBeEmpty();
});

it('does nothing when the process ended cleanly', function (): void {
    app(FatalReporter::class)->handle(null);

    expect(app(Spool::class)->files())->toBeEmpty();
});

it('leaves an uncaught exception to the handler that already reported it', function (): void {
    // An uncaught exception ends the process as a fatal too. The handler has
    // already filed it, with a real stack; this copy has none.
    app(Reporter::class)->report(new RuntimeException('the real one'));

    app(FatalReporter::class)->handle(fatal([
        'type' => E_ERROR,
        'message' => 'Uncaught RuntimeException: the real one in /app/x.php:1',
    ]));

    expect(app(Spool::class)->files())->toBeEmpty();
});

it('still reports an uncaught exception when nothing else did', function (): void {
    // The custom-handler case: nothing hooked the handler, so this is the only
    // account of the failure there will be.
    app(FatalReporter::class)->handle(fatal([
        'message' => 'Uncaught RuntimeException: nobody caught this in /app/x.php:1',
    ]));

    expect(app(Spool::class)->files())->toHaveCount(1);
});

it('names each fatal type it can be handed', function (): void {
    foreach ([E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR] as $i => $type) {
        app(FatalReporter::class)->handle(fatal(['type' => $type, 'message' => 'fatal '.$i]));
    }

    $types = collect(app(Spool::class)->files())
        ->flatMap(fn (string $file): array => app(Spool::class)->read($file))
        ->map(fn (array $payload): mixed => $payload['origin']['fatal_type'])
        ->all();

    expect($types)->toBe(['E_ERROR', 'E_PARSE', 'E_CORE_ERROR', 'E_COMPILE_ERROR', 'E_USER_ERROR']);
});

it('reserves memory once, however many times it is asked', function (): void {
    // A fresh one: the container's singleton was primed during boot, which is
    // exactly the second-call behaviour under test here.
    $fatals = new FatalReporter(fn (): Reporter => app(Reporter::class));

    $before = memory_get_usage();
    $fatals->reserveMemory(100000);
    $after = memory_get_usage();

    $fatals->reserveMemory(100000);

    expect($after - $before)->toBeGreaterThan(50000)
        ->and(memory_get_usage() - $after)->toBeLessThan(50000);
});

it('reads the last error when the shutdown function fires', function (): void {
    // Whatever error_get_last() holds during a test run is not a fatal, so the
    // interesting part is that this path runs at all and stays quiet.
    app(FatalReporter::class)->handleLast();

    expect(app(Spool::class)->files())->toBeEmpty();
});

it('says nothing when even reporting the fatal fails', function (): void {
    $fatals = new FatalReporter(fn (): Reporter => throw new RuntimeException('the container is gone'));

    $fatals->handle(fatal());
})->throwsNoExceptions();

it('resolves nothing at all when fatal capture is switched off', function (): void {
    config()->set('flare-client.fatals', false);

    $resolved = false;

    // Rebinding drops the instance boot already made, so this factory runs
    // only if something asks for it again.
    $this->app->singleton(FatalReporter::class, function () use (&$resolved): FatalReporter {
        $resolved = true;

        return new FatalReporter(fn (): Reporter => app(Reporter::class));
    });

    (new FlareClientServiceProvider($this->app))->boot();

    // An app that has switched this off should not pay for the handler, the
    // reserved memory, or the objects behind either.
    expect($resolved)->toBeFalse();
});
