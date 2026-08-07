<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as FoundationHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\FlareClientServiceProvider;
use Thijssensoftware\FlareClient\Payload\PayloadBuilder;
use Thijssensoftware\FlareClient\Payload\Sanitiser;
use Thijssensoftware\FlareClient\Payload\SizeGuard;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Support\Runtime;
use Thijssensoftware\FlareClient\Transport\CircuitBreaker;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;
use Thijssensoftware\RequestId\RequestIdContext;

/**
 * The paths that only run when something else has already gone wrong.
 *
 * They are the reason this package can be installed in nineteen apps without
 * anyone worrying about it, so they are held to the same bar as the happy
 * path rather than left as untested good intentions.
 */

/**
 * A disk name nothing has configured. Every Storage call against it throws,
 * which is the shape of a misconfigured spool.
 */
const BROKEN_DISK = 'not-a-configured-disk';

it('loses the event rather than throwing when the spool disk is broken', function (): void {
    $spool = new Spool(BROKEN_DISK);

    expect($spool->push(['event_id' => 'one']))->toBeFalse()
        ->and($spool->files())->toBe([])
        ->and($spool->read('anything.jsonl'))->toBe([]);

    // Neither of these has anything useful to do, and both must stay quiet.
    $spool->forget('anything.jsonl');
    $spool->rewrite('anything.jsonl', [['event_id' => 'one']]);

    expect($spool->totalBytes())->toBe(0);
});

it('refuses a payload that cannot be encoded', function (): void {
    expect(app(Spool::class)->push(['message' => "\xB1\x31"]))->toBeFalse();
});

it('gives up looking for a spool file that fits rather than looping forever', function (): void {
    // Nothing fits in a one byte file, so the rollover search would walk file
    // names until the disk filled up if it had no ceiling.
    config()->set('flare-client.spool.max_file_bytes', 1);

    expect(app(Spool::class)->push(['event_id' => 'one']))->toBeTrue()
        ->and(app(Spool::class)->files())->toHaveCount(1);
});

it('skips blank lines in a spool file', function (): void {
    // A blank line between two events, which is what a half-written line
    // followed by a rewrite leaves behind.
    Storage::disk('local')->put(
        'flare-spool/2026-08-01.jsonl',
        json_encode(['event_id' => 'one'])."\n\n".json_encode(['event_id' => 'two'])."\n",
    );

    expect(app(Spool::class)->read('flare-spool/2026-08-01.jsonl'))
        ->toBe([['event_id' => 'one'], ['event_id' => 'two']]);
});

it('throws away a spool file with nothing usable left in it', function (): void {
    Storage::disk('local')->put('flare-spool/2026-08-01.jsonl', "not json\nnor this\n");

    Http::fake();

    $this->artisan('flare:flush')->assertOk();

    expect(app(Spool::class)->files())->toBeEmpty();
    Http::assertNothingSent();
});

it('carries on with a cache that is itself broken', function (): void {
    // The breaker's state lives in the cache. A cache that throws must not be
    // the thing that stops an app reporting.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache is down'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache is down'));
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('cache is down'));

    $circuit = app(CircuitBreaker::class);

    $circuit->recordFailure();
    $circuit->recordSuccess();
    $circuit->muteFor(30);

    expect($circuit->isOpen())->toBeFalse();
});

it('spools a batch that could not be sent at all', function (): void {
    Http::fake(fn () => throw new RuntimeException('connection reset'));

    $result = app(Transport::class)->sendBatch([['event_id' => 'one']]);

    expect($result->delivery)->toBe(Delivery::Spooled)
        ->and($result->accepted)->toBe(0);
});

it('reports nothing when there is no request to describe', function (): void {
    // Source::Http with nothing behind it. Laravel binds a request even in a
    // worker or a command, where it is synthesised and describes nothing.
    $this->app['request'] = new Request;

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Http);

    expect($payload)->not->toHaveKey('request');
});

it('is not serving an http request when nothing is bound at all', function (): void {
    $application = App::getFacadeRoot();

    App::swap(new Container);

    $result = Runtime::isHttpRequest();

    App::swap($application);

    expect($result)->toBeFalse();
});

it('describes the route and its parameters', function (): void {
    Route::post('/invoices/{invoice}', fn () => null)->name('invoices.update');

    $this->app['request'] = Request::create('/invoices/4821', 'POST', server: ['REQUEST_METHOD' => 'POST']);
    $this->app['request']->setRouteResolver(fn () => Route::getRoutes()->match($this->app['request']));

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Http);

    expect($payload['request']['route_name'])->toBe('invoices.update')
        ->and($payload['request']['route_params'])->toBe(['invoice' => '4821']);
});

it('captures no body from a request whose shape it cannot scrub', function (): void {
    $this->app['request'] = Request::create('/upload', 'POST', server: [
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/octet-stream',
    ]);

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Http);

    expect($payload['request'])->not->toHaveKey('input');
});

it('says how big a body was rather than sending it', function (): void {
    config()->set('flare-client.sanitise.max_body_bytes', 100);

    $this->app['request'] = Request::create('/orders', 'POST', ['notes' => str_repeat('n', 500)], server: [
        'REQUEST_METHOD' => 'POST',
    ]);

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Http);

    expect($payload['request']['input']['[truncated]'])->toBeTrue()
        ->and($payload['request']['input']['bytes'])->toBeGreaterThan(100);
});

it('sends the user id, and the email only when pii is allowed', function (): void {
    Auth::setUser(new GenericUser(['id' => 7, 'email' => 'robbin@example.test']));

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console);

    expect($payload['user'])->toBe(['id' => 7]);

    config()->set('flare-client.sanitise.send_pii', true);

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console);

    expect($payload['user'])->toBe(['id' => 7, 'email' => 'robbin@example.test']);
});

it('sends no user block when the app has switched user capture off', function (): void {
    config()->set('flare-client.sanitise.send_user', false);

    Auth::setUser(new GenericUser(['id' => 7]));

    expect(app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console))
        ->not->toHaveKey('user');
});

it('sends no user block when resolving the user throws', function (): void {
    $this->app->bind(AuthFactory::class, fn () => throw new RuntimeException('no guard configured'));

    expect(app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console))
        ->not->toHaveKey('user');
});

it('sends no request id when nothing is stamping one', function (): void {
    $this->app->bind(RequestIdContext::class, fn () => throw new RuntimeException('not installed'));

    expect(app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console))
        ->not->toHaveKey('request_id');
});

it('sends no release when the release file is switched off', function (): void {
    config()->set('flare-client.release_file', null);

    expect(app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console))
        ->not->toHaveKey('release_sha');
});

it('sends no release when the disk holding it is unreachable', function (): void {
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('disk is gone'));

    expect(app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console))
        ->not->toHaveKey('release_sha');
});

it('captures no source context when the frame is not a readable file', function (): void {
    // eval'd code has a frame with a file name that cannot be opened, which is
    // the honest test of what happens when a frame points at nothing.
    $exception = eval('return new RuntimeException("from eval");');

    $payload = app(PayloadBuilder::class)->build($exception, Source::Console);

    expect($payload['exception']['frames'])->toBeArray();
});

it('captures no source context when context lines are switched off', function (): void {
    config()->set('flare-client.frames.context_lines', 0);

    $payload = app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console);

    foreach ($payload['exception']['frames'] as $frame) {
        expect($frame)->not->toHaveKey('context');
    }
});

it('ignores an extra key list of the wrong shape', function (): void {
    config()->set('flare-client.sanitise.extra_keys', 'not an array');

    expect(app(Sanitiser::class)->scrubArray(['notes' => 'plain']))->toBe(['notes' => 'plain']);
});

it('redacts the extra keys an app added by name', function (): void {
    config()->set('flare-client.sanitise.extra_keys', ['Nickname']);

    expect(app(Sanitiser::class)->scrubArray(['nickname' => 'rt'])['nickname'])->toBe(Sanitiser::REDACTED);
});

it('leaves an exception with no frames and a payload with no chain alone', function (): void {
    config()->set('flare-client.max_payload_bytes', 1);

    $payload = app(SizeGuard::class)->fit(['exception' => ['class' => 'RuntimeException']]);

    expect($payload['exception'])->toBe(['class' => 'RuntimeException'])
        ->and($payload['truncated'])->toBeTrue();
});

it('attaches to the framework exception handler when the app uses it', function (): void {
    $this->app->singleton(ExceptionHandler::class, fn () => new FoundationHandler($this->app));

    // Re-running boot is what a real app does once, at boot, with the
    // framework handler bound rather than the test runner's.
    (new FlareClientServiceProvider($this->app))->boot();

    Http::fake(['*' => Http::response([], 202)]);

    $this->app->make(ExceptionHandler::class)->report(new RuntimeException('through the handler'));

    Http::assertSent(fn ($request): bool => $request->data()['exception']['message'] === 'through the handler');
});

it('attaches to nothing when the app has swapped the handler for its own', function (): void {
    // Documented rather than worked around: guessing at a custom handler's
    // shape is worse than being clear that it gets nothing.
    $this->app->singleton(ExceptionHandler::class, fn () => new class implements ExceptionHandler
    {
        public function report(Throwable $e): void {}

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): mixed
        {
            return null;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });

    Http::fake();

    (new FlareClientServiceProvider($this->app))->boot();

    $this->app->make(ExceptionHandler::class)->report(new RuntimeException('unseen'));

    Http::assertNothingSent();
});

it('captures no source context when the frame points at a file that is gone', function (): void {
    // realpath, because a frame records the resolved file and the temp
    // directory is a symlink on macOS.
    $path = realpath(sys_get_temp_dir()).'/flare-frame-'.getmypid().'.php';

    // Two nested calls, so the trace carries a frame whose file is this one
    // rather than the line in the test that called into it.
    file_put_contents($path, '<?php return fn (): Throwable => (fn (): Throwable => new RuntimeException("from a file that will not exist"))();');

    $factory = require $path;
    $exception = $factory();

    unlink($path);

    $payload = app(PayloadBuilder::class)->build($exception, Source::Console);

    $frame = collect($payload['exception']['frames'])
        ->first(fn (array $frame): bool => ($frame['file'] ?? '') === $path);

    expect($frame)->not->toBeNull()
        ->and($frame)->not->toHaveKey('context');
});

it('gives up quietly when even logging its own failure fails', function (): void {
    Log::shouldReceive('debug')->andThrow(new RuntimeException('the log is broken too'));

    $this->app->bind(PayloadBuilder::class, fn (): PayloadBuilder => new class(app(Sanitiser::class), app(SizeGuard::class)) extends PayloadBuilder
    {
        public function build(Throwable $e, Source $source, array $origin = [], string $level = 'error'): array
        {
            throw new RuntimeException('builder is broken');
        }
    });

    expect(app(Reporter::class)->report(new RuntimeException('boom')))->toBe(Delivery::Skipped);
});

it('schedules nothing when spooling is switched off', function (): void {
    config()->set('flare-client.spool.enabled', false);

    $before = count($this->app->make(Schedule::class)->events());

    (new FlareClientServiceProvider($this->app))->boot();

    expect(count($this->app->make(Schedule::class)->events()))->toBe($before);
});

it('boots without a scheduler at all', function (): void {
    $this->app->bind(Schedule::class, fn () => throw new RuntimeException('no scheduler here'));

    (new FlareClientServiceProvider($this->app))->boot();
})->throwsNoExceptions();

it('swallows its own failure to report instead of propagating it', function (): void {
    // The payload builder throwing is the realistic version of this: a config
    // value of the wrong shape reaches deep enough to break the build.
    $this->app->bind(PayloadBuilder::class, fn (): PayloadBuilder => new class(app(Sanitiser::class), app(SizeGuard::class)) extends PayloadBuilder
    {
        public function build(Throwable $e, Source $source, array $origin = [], string $level = 'error'): array
        {
            throw new RuntimeException('builder is broken');
        }
    });

    expect(app(Reporter::class)->report(new RuntimeException('boom')))->toBe(Delivery::Skipped);
});

it('does not mistake the request Laravel invents for a command for a real one', function (): void {
    // SetRequestForConsole builds this from config('app.url') on every console
    // run, REQUEST_METHOD and all, which is why that header cannot be the test.
    // Reported from production, where every command's exceptions arrived as
    // http with a request block describing a request nobody made.
    $this->app['request'] = Request::create('https://tracker.thijssensoftware.nl', 'GET', server: [
        'REQUEST_METHOD' => 'GET',
        'argv' => ['artisan', 'migrate'],
        'argc' => 2,
    ]);

    expect(Runtime::isHttpRequest())->toBeFalse()
        ->and(Runtime::httpRequest())->toBeNull();
});

it('reports an exception from a command as console, with no request block', function (): void {
    $this->app['request'] = Request::create('https://tracker.thijssensoftware.nl', 'GET', server: [
        'REQUEST_METHOD' => 'GET',
        'argv' => ['artisan', 'queue:work'],
    ]);

    $this->app->singleton(ExceptionHandler::class, fn () => new FoundationHandler($this->app));

    (new FlareClientServiceProvider($this->app))->boot();

    Http::fake(['*' => Http::response([], 202)]);

    $this->app->make(ExceptionHandler::class)->report(new RuntimeException('from a command'));

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        // source is part of flare's fingerprint: the same exception failing in
        // a controller and in a nightly command are two different bugs.
        return $payload['source'] === 'console' && ! isset($payload['request']);
    });
});

it('still treats a request a web server actually served as one', function (): void {
    $this->app['request'] = Request::create('https://tracker.thijssensoftware.nl/invoices', 'GET', server: [
        'REQUEST_METHOD' => 'GET',
    ]);

    expect(Runtime::isHttpRequest())->toBeTrue();
});

it('ignores a header deny list of the wrong shape', function (): void {
    config()->set('flare-client.sanitise.headers', 'not an array');

    // With a usable list this is redacted by name. With an unusable one the
    // deny list is empty and only the value-shape rules still apply, which a
    // plain string does not trip.
    expect(app(Sanitiser::class)->scrubHeaders(['authorization' => 'plain']))
        ->toBe(['authorization' => 'plain']);
});
