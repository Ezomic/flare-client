<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Heartbeat\Heartbeats;
use Thijssensoftware\FlareClient\Heartbeat\TaskKey;
use Thijssensoftware\FlareClient\Transport\CircuitBreaker;
use Thijssensoftware\FlareClient\Transport\Transport;

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
});

function schedule(): Schedule
{
    return app(Schedule::class);
}

function finish(Event $task, int $exitCode = 0): void
{
    $task->exitCode = $exitCode;

    event(new ScheduledTaskFinished($task, 0.1));
}

/**
 * @return array<int, array<string, mixed>>
 */
function spooledBeats(): array
{
    $lines = [];

    foreach (Storage::disk('local')->files('flare-heartbeats') as $file) {
        foreach (explode("\n", trim((string) Storage::disk('local')->get($file))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

it('names a task by its artisan command, not the interpreter in front of it', function (): void {
    $task = schedule()->command('backup:database --force');

    expect(TaskKey::for($task))->toBe('backup:database --force');
});

it('knows a callback by the name it was given', function (): void {
    expect(TaskKey::for(schedule()->call(fn () => 1)->name('nightly-sync')))->toBe('nightly-sync');
});

it('falls back to the summary for something that is not a command', function (): void {
    expect(TaskKey::for(schedule()->call(fn () => 1)))->toBe('Callback');
});

it('appends a ping when a task finishes and posts nothing', function (): void {
    Http::fake();

    finish(schedule()->command('backup:database')->dailyAt('03:00')->timezone('Europe/Amsterdam'));

    $beats = spooledBeats();

    expect($beats)->toHaveCount(1)
        ->and($beats[0]['task'])->toBe('backup:database')
        ->and($beats[0]['outcome'])->toBe('success')
        ->and($beats[0]['expression'])->toBe('0 3 * * *')
        ->and($beats[0]['timezone'])->toBe('Europe/Amsterdam');

    Http::assertNothingSent();
});

it('records a failed run and a non-zero exit as failures', function (): void {
    $task = schedule()->command('reports:send');

    event(new ScheduledTaskFailed($task, new RuntimeException('x')));
    finish($task, 2);
    event(new ScheduledBackgroundTaskFinished($task));

    expect(array_column(spooledBeats(), 'outcome'))->toBe(['failure', 'failure', 'failure']);

    $task->exitCode = null;
    event(new ScheduledBackgroundTaskFinished($task));

    expect(array_column(spooledBeats(), 'outcome')[3])->toBe('success');
});

it('falls back to the app timezone', function (): void {
    config()->set('app.timezone', 'Europe/Lisbon');
    $task = schedule()->command('a');
    $task->timezone = null;
    finish($task);

    config()->set('app.timezone', null);
    finish($task);

    expect(array_column(spooledBeats(), 'timezone'))->toBe(['Europe/Lisbon', 'UTC']);
});

it('accepts a timezone object', function (): void {
    finish(schedule()->command('a')->timezone(new DateTimeZone('Asia/Tokyo')));

    expect(spooledBeats()[0]['timezone'])->toBe('Asia/Tokyo');
});

it('never pings for its own flush, nor when switched off', function (): void {
    finish(schedule()->command('flare:flush'));

    config()->set('flare-client.heartbeats.enabled', false);
    finish(schedule()->command('a'));

    config()->set('flare-client.heartbeats.enabled', true);
    config()->set('flare-client.enabled', false);
    finish(schedule()->command('b'));

    expect(spooledBeats())->toBe([]);
});

it('sends spooled pings in one batch from the flush and clears the spool', function (): void {
    Http::fake(['*' => Http::response(['accepted' => 2], 202)]);

    finish(schedule()->command('a')->hourly());
    finish(schedule()->command('b')->daily());

    $this->artisan('flare:flush')->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/heartbeat')
        && count($request['beats']) === 2
        && $request->hasHeader('X-Flare-Key'));

    expect(spooledBeats())->toBe([]);
});

it('reports the schedule once, with the first batch, and again only when it changes', function (): void {
    Http::fake(['*' => Http::response([], 202)]);

    schedule()->command('a')->hourly();
    schedule()->command('b')->everyFiveMinutes()->environments(['not-this-one']);
    schedule()->command('flare:flush')->everyMinute();

    finish(schedule()->command('a')->hourly());
    $this->artisan('flare:flush')->assertOk();

    Http::assertSent(fn ($request) => $request['schedule'] === [
        ['task' => 'a', 'expression' => '0 * * * *', 'timezone' => 'UTC'],
    ]);

    Http::fake(['*' => Http::response([], 202)]);
    finish(schedule()->command('a')->hourly());
    $this->artisan('flare:flush')->assertOk();

    Http::assertSent(fn ($request) => $request['schedule'] === []);

    Http::fake(['*' => Http::response([], 202)]);
    schedule()->command('c')->daily();
    $this->artisan('flare:flush')->assertOk();

    Http::assertSent(fn ($request) => $request['beats'] === [] && count($request['schedule']) === 2);
});

it('sends nothing when there is nothing to say', function (): void {
    Http::fake();

    $this->artisan('flare:flush')->assertOk();

    Http::assertNothingSent();
});

it('keeps the pings spooled when flare cannot be reached', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    finish(schedule()->command('a'));
    $this->artisan('flare:flush')->assertOk();

    expect(spooledBeats())->toHaveCount(1);
});

it('keeps the schedule for next time when it could not be delivered', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    schedule()->command('a')->hourly();
    $this->artisan('flare:flush')->assertOk();
    $this->artisan('flare:flush')->assertOk();

    Http::assertSentCount(2);
});

it('resumes mid-file after a partial delivery', function (): void {
    config()->set('flare-client.heartbeats.batch_size', 2);

    Http::fake(['*' => Http::sequence()->push([], 202)->push([], 500)]);

    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        finish(schedule()->command($name));
    }

    $this->artisan('flare:flush')->assertOk();

    expect(array_column(spooledBeats(), 'task'))->toBe(['c', 'd', 'e']);
});

it('drops pings flare refuses for good', function (): void {
    Http::fake(['*' => Http::response([], 422)]);

    finish(schedule()->command('a'));
    $this->artisan('flare:flush')->assertOk();

    expect(spooledBeats())->toBe([]);
});

it('mutes on a 429 and keeps the pings', function (): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '30'])]);

    finish(schedule()->command('a'));
    $this->artisan('flare:flush')->assertOk();

    expect(spooledBeats())->toHaveCount(1)
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});

it('keeps the pings while the circuit is open or the request throws', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    finish(schedule()->command('a'));

    expect(app(Transport::class)->sendHeartbeats([['task' => 'a']], []))->toBeFalse();

    app(CircuitBreaker::class)->muteFor(60);

    expect(app(Transport::class)->sendHeartbeats([['task' => 'a']], []))->toBeFalse();
});

it('survives a cache that is broken', function (): void {
    Http::fake(['*' => Http::response([], 202)]);
    config()->set('cache.default', 'broken');
    config()->set('cache.stores.broken', ['driver' => 'file', 'path' => '/dev/null/nope']);

    schedule()->command('a')->hourly();

    expect(fn () => $this->artisan('flare:flush')->assertOk())->not->toThrow(Throwable::class);
});

it('does not report the schedule when the cache cannot say what flare already has', function (): void {
    Http::fake();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    schedule()->command('a')->hourly();

    expect(app(Heartbeats::class)->flush())->toBe(0);

    Http::assertNothingSent();
});

it('does nothing from the flush when switched off', function (): void {
    Http::fake();
    config()->set('flare-client.heartbeats.enabled', false);

    schedule()->command('a')->hourly();

    expect(app(Heartbeats::class)->flush())->toBe(0);

    Http::assertNothingSent();
});

it('falls back to defaults for bad config', function (): void {
    config()->set('flare-client.heartbeats.path', '');
    config()->set('flare-client.heartbeats.batch_size', 'x');

    finish(schedule()->command('a'));

    expect(Storage::disk('local')->files('flare-heartbeats'))->toHaveCount(1);
});
