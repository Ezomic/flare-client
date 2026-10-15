<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Thijssensoftware\FlareClient\Breadcrumbs\Breadcrumbs;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Payload\PayloadBuilder;
use Thijssensoftware\FlareClient\Payload\Sanitiser;
use Thijssensoftware\FlareClient\ProvidesFingerprint;
use Thijssensoftware\FlareClient\Reporter;

beforeEach(function (): void {
    Http::fake(['*' => Http::response(['outcome' => 'stored'], 202)]);
});

function crumbsFor(): array
{
    return app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console)['breadcrumbs'] ?? [];
}

function lastPayload(?string $source = null): array
{
    $payloads = Http::recorded()
        ->map(fn (array $pair): array => $pair[0]->data())
        ->filter(fn (array $payload): bool => $source === null || $payload['source'] === $source);

    return $payloads->last();
}

it('attaches the log lines before the error, oldest first, with level, message, channel and timestamp', function (): void {
    Log::info('import started');
    Log::warning('skipped a row', ['row' => 12]);

    $crumbs = crumbsFor();

    expect($crumbs)->toHaveCount(2)
        ->and($crumbs[0])->toHaveKeys(['level', 'message', 'channel', 'timestamp'])
        ->and($crumbs[0]['level'])->toBe('info')
        ->and($crumbs[0]['message'])->toBe('import started')
        ->and($crumbs[1]['level'])->toBe('warning')
        ->and($crumbs[1]['context'])->toBe(['row' => 12])
        ->and($crumbs[0]['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/');
});

it('omits the key when nothing was logged', function (): void {
    expect(app(PayloadBuilder::class)->build(new RuntimeException('boom'), Source::Console))
        ->not->toHaveKey('breadcrumbs');
});

it('captures with the log source switched off', function (): void {
    config()->set('flare-client.sources.log', false);

    Log::debug('still recorded');
    Log::emergency('and nothing is reported for it');

    Http::assertNothingSent();
    expect(crumbsFor())->toHaveCount(2);
});

it('does not repeat a reported log line as its own breadcrumb', function (): void {
    config()->set('flare-client.sources.log', true);

    Log::info('before');
    Log::error('the reported one');

    $payload = lastPayload('log');

    expect(array_column($payload['breadcrumbs'], 'message'))->toBe(['before']);
    expect(array_column(crumbsFor(), 'message'))->toBe(['before', 'the reported one']);
});

it('reports a real exception with the lines that led up to it, the error last', function (): void {
    Log::info('step one');
    Log::info('step two');

    app(Reporter::class)->report(new RuntimeException('step three failed'), Source::Console);

    $payload = lastPayload();

    expect(array_column($payload['breadcrumbs'], 'message'))->toBe(['step one', 'step two'])
        ->and($payload['exception']['message'])->toBe('step three failed')
        ->and(array_keys($payload))->toContain('breadcrumbs', 'exception');
});

it('scrubs tokens, passwords and authorization headers in context and message', function (): void {
    Log::info('Bearer abcdefghijklmnop');
    Log::info('calling the api', [
        'token' => 'secret-token-value',
        'password' => 'hunter2',
        'headers' => ['Authorization' => 'Bearer abc.def.ghi', 'Accept' => 'json'],
        'nested' => ['deeper' => ['api_key' => 'sk'.'_live_'.'abcdefghijklmnop']],
        'note' => 'sk'.'_live_'.'abcdefghijklmnopqrstuvwx',
        'hash' => str_repeat('a', 40),
    ]);

    $crumbs = crumbsFor();
    $encoded = json_encode($crumbs);

    expect($crumbs[0]['message'])->toBe('[filtered]')
        ->and($crumbs[1]['context']['token'])->toBe('[filtered]')
        ->and($crumbs[1]['context']['headers']['Authorization'])->toBe('[filtered]')
        ->and($crumbs[1]['context']['headers']['Accept'])->toBe('json')
        ->and($crumbs[1]['context']['note'])->toBe('[filtered]')
        ->and($crumbs[1]['context']['hash'])->toBe('[filtered]')
        ->and($encoded)->not->toContain('hunter2')
        ->and($encoded)->not->toContain('secret-token-value')
        ->and($encoded)->not->toContain('sk_live_');
});

it('keeps only the newest records up to the count cap', function (): void {
    config()->set('flare-client.breadcrumbs.limit', 3);

    foreach (range(1, 10) as $i) {
        Log::info('line '.$i);
    }

    expect(array_column(crumbsFor(), 'message'))->toBe(['line 8', 'line 9', 'line 10']);
});

it('keeps the buffer under the size cap by dropping the oldest', function (): void {
    config()->set('flare-client.breadcrumbs.max_bytes', 600);

    foreach (range(1, 20) as $i) {
        Log::info('line '.$i.' '.str_repeat('x', 100));
    }

    $crumbs = crumbsFor();

    expect(strlen((string) json_encode($crumbs)))->toBeLessThanOrEqual(600)
        ->and($crumbs)->not->toBeEmpty()
        ->and(end($crumbs)['message'])->toStartWith('line 20');
});

it('refuses a single record bigger than the whole budget', function (): void {
    config()->set('flare-client.breadcrumbs.max_bytes', 50);

    Log::info('this one is far too large for fifty bytes');

    expect(crumbsFor())->toBe([]);
});

it('captures nothing with a limit of zero or when switched off', function (): void {
    config()->set('flare-client.breadcrumbs.limit', 0);
    Log::info('nope');
    expect(crumbsFor())->toBe([]);

    config()->set('flare-client.breadcrumbs.limit', 5);
    config()->set('flare-client.breadcrumbs.enabled', false);
    Log::info('nope either');
    expect(crumbsFor())->toBe([]);
});

it('captures nothing when the client is disabled or has no key', function (): void {
    config()->set('flare-client.key', null);
    Log::info('no key');

    config()->set('flare-client.key', 'flr_testkeytestkeytest');
    config()->set('flare-client.enabled', false);
    Log::info('disabled');

    config()->set('flare-client.enabled', true);
    expect(crumbsFor())->toBe([]);
});

it('clamps an enormous or malformed limit instead of growing without bound', function (): void {
    config()->set('flare-client.breadcrumbs.limit', 1_000_000_000);
    config()->set('flare-client.breadcrumbs.max_bytes', 1_000_000_000);

    foreach (range(1, 150) as $i) {
        Log::info('l'.$i);
    }

    expect(crumbsFor())->toHaveCount(100);

    config()->set('flare-client.breadcrumbs.limit', 'lots');
    Log::info('one more');

    expect(crumbsFor())->toHaveCount(20);
});

it('normalises the level and refuses one that is not a word', function (): void {
    event(new MessageLogged('ERROR', 'shouty'));
    event(new MessageLogged("bad\x00level", 'junk'));
    event(new MessageLogged('Warning', 'mixed'));

    expect(array_column(crumbsFor(), 'level'))->toBe(['error', 'unknown', 'warning']);
});

it('copes with an empty message', function (): void {
    Log::info('');

    expect(crumbsFor()[0]['message'])->toBe('');
});

it('truncates a megabyte message and stays well under the budget', function (): void {
    Log::info(str_repeat('word ', 200_000));

    $crumbs = crumbsFor();

    expect($crumbs)->toHaveCount(1)
        ->and(mb_strlen($crumbs[0]['message']))->toBeLessThanOrEqual(300);
});

it('still scrubs a secret that sits in a huge message', function (): void {
    Log::info(str_repeat('a', 2_000_000));

    expect(crumbsFor()[0]['message'])->toBe('[filtered]');
});

it('keeps the payload encodable when a message or context holds invalid utf-8', function (): void {
    event(new MessageLogged('info', "broken \xB1\x31 bytes", ['name' => "bad \xC3\x28 value", "k\xB1" => 'x']));

    $crumbs = crumbsFor();

    expect(json_encode($crumbs))->not->toBeFalse()
        ->and($crumbs)->toHaveCount(1);
});

it('accepts stringable messages and survives one that throws', function (): void {
    event(new MessageLogged('info', new class implements Stringable
    {
        public function __toString(): string
        {
            return 'from an object';
        }
    }));

    event(new MessageLogged('info', new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('no');
        }
    }));

    event(new MessageLogged('info', ['not', 'a', 'string']));

    expect(array_column(crumbsFor(), 'message'))->toBe(['from an object', '[unprintable]', '[array]']);
});

it('holds no reference to objects in the context', function (): void {
    Log::info('with an object', ['model' => new ArrayObject([1, 2, 3]), 'exception' => new RuntimeException('x')]);

    $context = crumbsFor()[0]['context'];

    expect($context['model'])->toBe('[object]')
        ->and($context['exception'])->toBe('[object]');
});

it('survives circular and very deep context', function (): void {
    $loop = ['name' => 'loop'];
    $loop['self'] = &$loop;

    $deep = [];
    $cursor = &$deep;

    foreach (range(1, 50) as $ignored) {
        $cursor['next'] = [];
        $cursor = &$cursor['next'];
    }

    Log::info('cyclic', ['loop' => $loop, 'deep' => $deep]);

    expect(crumbsFor())->toHaveCount(1)
        ->and(json_encode(crumbsFor()))->not->toBeFalse();
});

it('replaces an oversized or unencodable context with a marker', function (): void {
    Log::info('huge context', ['blob' => str_repeat('z', 800), 'other' => str_repeat('y', 800)]);
    Log::info('not a number', ['ratio' => NAN]);

    $crumbs = crumbsFor();

    expect($crumbs[0]['context'])->toBe(['[truncated]' => true])
        ->and(json_encode($crumbs))->not->toBeFalse();
});

it('only looks at the first keys of a wide context', function (): void {
    Log::info('wide', array_fill_keys(array_map(fn (int $i): string => 'k'.$i, range(1, 500)), 1));

    expect(crumbsFor()[0]['context'])->toHaveCount(20);
});

it('does not record anything flare logs about itself', function (): void {
    Log::debug('flare-client failed to report an exception');
    Log::debug('flare-client trimmed an oversized payload');
    Log::debug('flare heartbeats: a scheduled callback has no name');
    Log::info('an app line');

    expect(array_column(crumbsFor(), 'message'))->toBe(['an app line']);
});

it('does not turn logging during payload construction into breadcrumbs', function (): void {
    config()->set('flare-client.max_payload_bytes', 1);

    // The guard writes a debug line when it trims, and does so on every
    // oversized payload: it must not appear on the next event.
    app(Reporter::class)->report(new RuntimeException('first'), Source::Console);
    app(Reporter::class)->report(new RuntimeException('second'), Source::Console);

    expect(app(Breadcrumbs::class)->all())->toBe([]);
});

it('does not loop or add breadcrumbs when reporting itself fails', function (): void {
    config()->set('flare-client.sources.log', true);
    config()->set('flare-client.log_level', 'debug');

    Http::fake(fn () => throw new RuntimeException('flare is down'));

    Log::error('trigger');

    expect(array_column(app(Breadcrumbs::class)->all(), 'message'))->toBe(['trigger']);
});

it('never lets a failing breadcrumb break the log call', function (): void {
    $this->app->bind(Sanitiser::class, fn (): Sanitiser => new class extends Sanitiser
    {
        public function scrubString(string $value): string
        {
            throw new RuntimeException('the sanitiser is broken');
        }
    });

    Log::info('still logs');
    Log::info('and again');

    expect(app(Breadcrumbs::class)->all())->toBe([]);
});

it('does not record what app code logs while a payload is built', function (): void {
    $exception = new class('hook') extends RuntimeException implements ProvidesFingerprint
    {
        public function flareFingerprint(): ?string
        {
            Log::info('logged from the fingerprint hook');

            return 'key';
        }
    };

    app(Reporter::class)->report($exception, Source::Console);

    expect(app(Breadcrumbs::class)->all())->toBe([]);
});

it('redacts a secret inside a sentence, not only a message that is one', function (): void {
    Log::error('Login failed, Authorization: Bearer abcdef123456 for card 4111 1111 1111 1111 hex 0123456789abcdef0123456789abcdef0123 jwt eyJhbGciOi.eyJzdWIi.sig', [
        'note' => 'token=abc123 password=hunter2',
        'url' => 'https://a.test/reset?token=SECRET&page=2',
        'nested' => ['line' => 'retry with api_key: "xyz 789"'],
    ]);

    $crumb = crumbsFor()[0];
    $encoded = json_encode($crumb);

    foreach (['abcdef123456', '4111', '0123456789abcdef', 'eyJhbGciOi', 'abc123', 'hunter2', 'SECRET', 'xyz 789'] as $secret) {
        expect($encoded)->not->toContain($secret);
    }

    expect($crumb['message'])->toContain('Login failed')
        ->and($crumb['context']['url'])->toContain('page=2');
});

it('does not spend a walk over a huge context on every log line', function (): void {
    $started = hrtime(true);

    foreach (range(1, 20) as $ignored) {
        Log::info('collection', ['rows' => range(1, 200_000), 'big' => str_repeat('x', 5_000_000)]);
    }

    $millis = (hrtime(true) - $started) / 1_000_000;

    expect($millis)->toBeLessThan(500)
        ->and(strlen((string) json_encode(crumbsFor())))->toBeLessThan(16384);
});

it('empties the buffer when it is reset', function (): void {
    Log::info('one');
    app(Breadcrumbs::class)->reset();

    expect(crumbsFor())->toBe([]);
});

function fakeJob(): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

it('starts every queue job with an empty buffer', function (): void {
    Log::info('job one line');

    event(new JobProcessing('redis', fakeJob()));
    Log::info('job two line');

    expect(array_column(crumbsFor(), 'message'))->toBe(['job two line']);
});

it('keeps a request breadcrumbs when a sync job runs inside it', function (): void {
    Log::info('request line');

    event(new JobProcessing('sync', fakeJob()));
    Log::info('sync job line');

    expect(array_column(crumbsFor(), 'message'))->toBe(['request line', 'sync job line']);
});

it('treats a connection by its driver, whatever it is called', function (): void {
    config()->set('queue.connections.inline', ['driver' => 'sync']);

    Log::info('request line');
    event(new JobProcessing('inline', fakeJob()));
    Log::info('inline job line');

    expect(array_column(crumbsFor(), 'message'))->toBe(['request line', 'inline job line']);
});

it('resets per request in an octane worker', function (): void {
    Log::info('request one');

    event('Laravel\Octane\Events\RequestReceived');
    Log::info('request two');

    expect(array_column(crumbsFor(), 'message'))->toBe(['request two']);
});

it('does not carry breadcrumbs from one worker job into the next, through a real queue worker', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
    ]);
    config()->set('queue.failed.driver', 'null');
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    Schema::create('jobs', function ($table): void {
        $table->bigIncrements('id');
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    dispatch(new LogsThenMaybeFails('first job line', fail: false));
    dispatch(new LogsThenMaybeFails('second job line', fail: true));

    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);

    $payload = lastPayload('job');

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(array_column($payload['breadcrumbs'], 'message'))->toBe(['second job line', 'Job failing on purpose'])
        ->and(json_encode($payload['breadcrumbs']))->not->toContain('first job line');
});

it('does not carry breadcrumbs from one scheduled task into the next', function (): void {
    $schedule = app(Schedule::class);

    $schedule->call(function (): void {
        Log::info('task one line');
    })->name('task-one');

    $schedule->call(function (): void {
        Log::info('task two line');

        throw new RuntimeException('task two failed');
    })->name('task-two');

    Artisan::call('schedule:run');

    $payload = lastPayload('schedule');

    expect(array_column($payload['breadcrumbs'], 'message'))->toBe(['task two line'])
        ->and($payload['exception']['message'])->toBe('task two failed');
});

final class LogsThenMaybeFails implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $line, public bool $fail) {}

    public function handle(): void
    {
        Log::info($this->line);

        if ($this->fail) {
            Log::warning('Job failing on purpose');

            throw new RuntimeException('job failed');
        }
    }
}
