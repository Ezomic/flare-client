<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Heartbeat\Heartbeats;
use Thijssensoftware\FlareClient\Heartbeat\Run;
use Thijssensoftware\FlareClient\Heartbeat\TaskKey;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Transport\CircuitBreaker;
use Thijssensoftware\FlareClient\Transport\Spool;
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

function heartbeatSpool(): Spool
{
    return new Spool('local', 'flare-heartbeats');
}

/**
 * @return array<int, array<string, mixed>>
 */
function spooledBeats(): array
{
    $beats = [];

    foreach (Storage::disk('local')->files('flare-heartbeats') as $file) {
        foreach (explode("\n", trim((string) Storage::disk('local')->get($file))) as $line) {
            $beats[] = json_decode($line, true);
        }
    }

    return $beats;
}

describe('keys', function (): void {
    it('names a task by its artisan command, not the interpreter in front of it', function (): void {
        $task = schedule()->command('backup:database --force');

        expect(TaskKey::for($task))->toBe('backup:database --force')
            ->and(TaskKey::description($task))->toBe('backup:database --force');
    });

    it('replaces values that change every run in the key, and keeps them in the description', function (): void {
        $task = schedule()->command('report:send 2026-10-09 --at=1791560000 --id=7 --run=4f9c2b1e-3a5d-4e8f-9c1a-2b3c4d5e6f70');

        expect(TaskKey::for($task))->toBe('report:send {date} --at={n} --id=7 --run={uuid}')
            ->and(TaskKey::description($task))->toContain('2026-10-09')
            ->and(TaskKey::for(schedule()->command('report:send "2026-10-09 10:15:00"')))->toBe('report:send "{date}"');
    });

    it('does not mistake the word artisan inside a path for the token', function (): void {
        expect(TaskKey::for(schedule()->exec('cat /srv/artisanal/log')))->toStartWith('cat /srv/artisanal/log');
    });

    it('has no key for an unnamed callback, and knows a named one', function (): void {
        expect(TaskKey::for(schedule()->call(fn () => 1)))->toBeNull()
            ->and(TaskKey::for(schedule()->call(fn () => 1)->name('nightly-sync')))->toBe('nightly-sync');
    });
});

describe('recording', function (): void {
    it('appends a ping when a task finishes and posts nothing', function (): void {
        Http::fake();

        finish(schedule()->command('backup:database')->dailyAt('03:00')->timezone('Europe/Amsterdam'));

        $beats = spooledBeats();

        expect($beats)->toHaveCount(1)
            ->and($beats[0]['task'])->toBe('backup:database')
            ->and($beats[0]['description'])->toBe('backup:database')
            ->and($beats[0]['outcome'])->toBe('success')
            ->and($beats[0]['expression'])->toBe('0 3 * * *')
            ->and($beats[0]['timezone'])->toBe('Europe/Amsterdam');

        Http::assertNothingSent();
    });

    it('records a failed run once even though it raises both events', function (): void {
        $task = schedule()->command('reports:send');

        event(new ScheduledTaskStarting($task));
        finish($task, 2);
        event(new ScheduledTaskFailed($task, new RuntimeException('x')));

        expect(array_column(spooledBeats(), 'outcome'))->toBe(['failure']);

        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFailed($task, new RuntimeException('x')));

        expect(array_column(spooledBeats(), 'outcome'))->toBe(['failure', 'failure']);
    });

    it('waits for a background task to really finish', function (): void {
        $task = schedule()->command('slow:job')->runInBackground();

        finish($task);

        expect(spooledBeats())->toBe([]);

        $task->exitCode = 3;
        event(new ScheduledBackgroundTaskFinished($task));

        $task->exitCode = null;
        event(new ScheduledBackgroundTaskFinished($task));

        expect(array_column(spooledBeats(), 'outcome'))->toBe(['failure', 'success']);
    });

    it('sends no expression for a task that decides at run time whether to run', function (): void {
        finish(schedule()->command('a')->hourly()->when(fn () => true));
        finish(schedule()->command('b')->hourly()->skip(fn () => false));
        finish(schedule()->command('c')->hourly()->between('08:00', '18:00'));
        finish(schedule()->command('d')->hourly());

        expect(array_column(spooledBeats(), 'expression'))->toBe([null, null, null, '0 * * * *']);
    });

    it('falls back to the app timezone, and accepts a timezone object', function (): void {
        config()->set('app.timezone', 'Europe/Lisbon');
        $task = schedule()->command('a');
        $task->timezone = null;
        finish($task);

        config()->set('app.timezone', null);
        event(new ScheduledTaskStarting($task));
        finish($task);

        finish(schedule()->command('b')->timezone(new DateTimeZone('Asia/Tokyo')));

        expect(array_column(spooledBeats(), 'timezone'))->toBe(['Europe/Lisbon', 'UTC', 'Asia/Tokyo']);
    });

    it('never pings for flare itself, nor when switched off', function (): void {
        finish(schedule()->command('flare:flush'));

        config()->set('flare-client.heartbeats.enabled', false);
        finish(schedule()->command('a'));

        config()->set('flare-client.heartbeats.enabled', true);
        config()->set('flare-client.enabled', false);
        finish(schedule()->command('b'));

        expect(spooledBeats())->toBe([]);
    });

    it('says once at debug level that an unnamed callback is not monitored', function (): void {
        Log::spy();

        finish(schedule()->call(fn () => 1));
        finish(schedule()->call(fn () => 2));

        Log::shouldHaveReceived('debug')->once();
        expect(spooledBeats())->toBe([]);
    });

    it('survives a log that cannot be written', function (): void {
        Log::shouldReceive('debug')->andThrow(new RuntimeException('disk full'));

        finish(schedule()->call(fn () => 1));

        expect(spooledBeats())->toBe([]);
    });

    it('lets nothing a heartbeat does reach the scheduler', function (): void {
        app()->bind(Heartbeats::class, fn () => throw new RuntimeException('cannot build'));

        $task = schedule()->command('a');

        event(new ScheduledTaskStarting($task));
        finish($task);
        event(new ScheduledTaskFailed($task, new RuntimeException('x')));
        event(new ScheduledBackgroundTaskFinished($task));

        expect(true)->toBeTrue();
    });

    it('does not pass on a task that cannot be described', function (): void {
        $heartbeats = Mockery::mock(Heartbeats::class);
        $heartbeats->shouldReceive('record')->andThrow(new RuntimeException('summary exploded'));
        app()->instance(Heartbeats::class, $heartbeats);

        finish(schedule()->command('a'));

        expect(true)->toBeTrue();
    });
});

describe('flushing', function (): void {
    it('sends spooled pings in one batch and clears the spool', function (): void {
        Http::fake(['*' => Http::response(['accepted' => 2], 202)]);

        finish(schedule()->command('a')->hourly());
        finish(schedule()->command('b')->daily());

        $this->artisan('flare:flush')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/heartbeat')
            && count($request['beats']) === 2
            && $request->hasHeader('X-Flare-Key'));

        expect(spooledBeats())->toBe([])
            ->and(Storage::disk('local')->files('flare-heartbeats'))->toBe([]);
    });

    it('sends the events first, then the heartbeats', function (): void {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

        app(Spool::class)->push(['event_id' => 'one']);
        finish(schedule()->command('a'));

        $this->artisan('flare:flush')->assertOk();

        $urls = Http::recorded()->map(fn ($pair) => basename($pair[0]->url()))->all();

        expect($urls)->toBe(['batch', 'heartbeat']);
    });

    it('still sends events when flare answers 404 for the heartbeat route', function (): void {
        Http::fake([
            '*/api/heartbeat' => Http::response([], 404),
            '*' => Http::response(['accepted' => 1], 202),
        ]);

        finish(schedule()->command('a'));
        $this->artisan('flare:flush')->assertOk();

        expect(app(CircuitBreaker::class)->isOpen())->toBeFalse()
            ->and(app(Transport::class)->sendBatch([['event_id' => 'x']])->accepted)->toBe(1)
            ->and(spooledBeats())->toHaveCount(1);

        Cache::flush();
        expect(app(Transport::class)->sendBatch([['event_id' => 'y']])->accepted)->toBe(1);
    });

    it('backs off for half an hour on a 404 and five minutes on a 5xx, on a key of its own', function (): void {
        Http::fake(['*' => Http::response([], 404)]);

        finish(schedule()->command('a'));
        $this->artisan('flare:flush')->assertOk();
        $this->artisan('flare:flush')->assertOk();

        Http::assertSentCount(1);

        $until = Cache::get('flare-client:heartbeat-backoff-until');
        expect($until)->toBeGreaterThan(time() + 1700)
            ->and(Cache::get('flare-client:open-until'))->toBeNull()
            ->and(Cache::get('flare-client:failures'))->toBeNull();

    });

    it('backs off for five minutes on a 5xx', function (): void {
        Http::fake(['*' => Http::response([], 503)]);

        finish(schedule()->command('a'));
        $this->artisan('flare:flush')->assertOk();

        expect(Cache::get('flare-client:heartbeat-backoff-until'))->toBeBetween(time() + 200, time() + 400)
            ->and(Cache::get('flare-client:failures'))->toBeNull();
    });

    it('honours Retry-After on a 429 without muting errors', function (): void {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '90'])]);

        finish(schedule()->command('a'));
        $this->artisan('flare:flush')->assertOk();

        expect(Cache::get('flare-client:heartbeat-backoff-until'))->toBeBetween(time() + 60, time() + 100)
            ->and(app(CircuitBreaker::class)->isOpen())->toBeFalse()
            ->and(spooledBeats())->toHaveCount(1);
    });

    it('keeps the pings when flare cannot be reached, without tripping the circuit', function (): void {
        Http::fake(fn () => throw new ConnectionException('down'));

        finish(schedule()->command('a'));

        for ($i = 0; $i < 5; $i++) {
            Cache::forget('flare-client:heartbeat-backoff-until');
            $this->artisan('flare:flush')->assertOk();
        }

        expect(spooledBeats())->toHaveCount(1)
            ->and(app(CircuitBreaker::class)->isOpen())->toBeFalse();
    });

    it('waits without a backoff while the error circuit is open', function (): void {
        Http::fake();
        app(CircuitBreaker::class)->muteFor(60);

        finish(schedule()->command('a'));
        $this->artisan('flare:flush')->assertOk();

        Http::assertNothingSent();
        expect(spooledBeats())->toHaveCount(1)
            ->and(Cache::get('flare-client:heartbeat-backoff-until'))->toBeNull();
    });

    it('drops pings flare refuses for good', function (): void {
        Http::fake(['*' => Http::response([], 422)]);

        finish(schedule()->command('a'));
        $this->artisan('flare:flush')->assertOk();

        expect(spooledBeats())->toBe([]);
    });

    it('does not lose a ping appended while the batch is on its way', function (): void {
        Http::fake(function () {
            heartbeatSpool()->push(['task' => 'late', 'outcome' => 'success']);

            return Http::response([], 202);
        });

        finish(schedule()->command('early'));
        $this->artisan('flare:flush')->assertOk();

        expect(array_column(spooledBeats(), 'task'))->toBe(['late']);
    });

    it('puts back what it could not send next to what arrived meanwhile', function (): void {
        config()->set('flare-client.heartbeats.batch_size', 2);

        $calls = 0;

        Http::fake(function () use (&$calls) {
            heartbeatSpool()->push(['task' => 'late'.$calls, 'outcome' => 'success']);

            return Http::response([], $calls++ === 0 ? 202 : 500);
        });

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            finish(schedule()->command($name));
        }

        $this->artisan('flare:flush')->assertOk();

        expect(array_column(spooledBeats(), 'task'))->toContain('c', 'd', 'e', 'late0', 'late1')
            ->and(array_column(spooledBeats(), 'task'))->not->toContain('a', 'b');
    });

    it('finishes a claim whose sender died, and leaves a recent one alone', function (): void {
        $spool = heartbeatSpool();
        $line = json_encode(['task' => 'orphan', 'outcome' => 'success'])."\n";

        Storage::disk('local')->put('flare-heartbeats/2026-10-01.jsonl.sending-1-'.(time() - 3600), $line);
        Storage::disk('local')->put('flare-heartbeats/2026-10-02.jsonl.sending-2-'.time(), $line);

        Http::fake(['*' => Http::response([], 202)]);
        $this->artisan('flare:flush')->assertOk();

        Http::assertSent(fn ($request) => $request['beats'][0]['task'] === 'orphan');

        expect(Storage::disk('local')->files('flare-heartbeats'))->toHaveCount(1)
            ->and($spool->staleClaims(600))->toBe([]);
    });

    it('sends only a few batches a run and keeps the rest for the next', function (): void {
        config()->set('flare-client.heartbeats.batch_size', 1);
        config()->set('flare-client.heartbeats.max_batches', 2);
        Http::fake(['*' => Http::response([], 202)]);

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            finish(schedule()->command($name));
        }

        $this->artisan('flare:flush')->assertOk();

        Http::assertSentCount(2);
        expect(array_column(spooledBeats(), 'task'))->toBe(['c', 'd', 'e']);
    });

    it('measures its time budget', function (): void {
        expect((new Run(microtime(true) - 30, 10, 20))->spent())->toBeTrue()
            ->and((new Run(microtime(true), 10, 20))->spent())->toBeFalse();
    });

    it('reports the schedule once, with the first batch, and again only when it changes', function (): void {
        Http::fake(['*' => Http::response([], 202)]);

        schedule()->command('a')->hourly();
        schedule()->command('b')->everyFiveMinutes()->environments(['not-this-one']);
        schedule()->command('flare:flush')->everyMinute();
        schedule()->call(fn () => 1);
        schedule()->command('f')->daily()->when(fn () => true);

        finish(schedule()->command('a')->hourly());
        $this->artisan('flare:flush')->assertOk();

        Http::assertSent(fn ($request) => $request['schedule'] === [
            ['task' => 'a', 'description' => 'a', 'expression' => '0 * * * *', 'timezone' => 'UTC'],
            ['task' => 'f', 'description' => 'f', 'expression' => null, 'timezone' => 'UTC'],
        ]);

        Http::fake(['*' => Http::response([], 202)]);
        finish(schedule()->command('a')->hourly());
        $this->artisan('flare:flush')->assertOk();

        Http::assertSent(fn ($request) => $request['schedule'] === []);

        Http::fake(['*' => Http::response([], 202)]);
        schedule()->command('c')->daily();
        $this->artisan('flare:flush')->assertOk();

        Http::assertSent(fn ($request) => $request['beats'] === [] && count($request['schedule']) === 3);
    });

    it('sends nothing when there is nothing to say', function (): void {
        Http::fake();

        $this->artisan('flare:flush')->assertOk();

        Http::assertNothingSent();
    });

    it('keeps the schedule for next time when it could not be delivered', function (): void {
        Http::fake(['*' => Http::response([], 500)]);

        schedule()->command('a')->hourly();
        $this->artisan('flare:flush')->assertOk();
        Cache::forget('flare-client:heartbeat-backoff-until');
        $this->artisan('flare:flush')->assertOk();

        Http::assertSentCount(2);
    });

    it('does not report the schedule when the cache cannot say what flare already has', function (): void {
        Http::fake();
        Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

        schedule()->command('a')->hourly();

        expect(app(Heartbeats::class)->flush())->toBe(0);

        Http::assertNothingSent();
    });

    it('survives a cache that cannot be written', function (): void {
        Http::fake(['*' => Http::response([], 404)]);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andThrow(new RuntimeException('read only'));
        Cache::shouldReceive('forever')->andThrow(new RuntimeException('read only'));

        schedule()->command('a')->hourly();

        expect(app(Heartbeats::class)->flush())->toBe(0);

        Http::fake(['*' => Http::response([], 202)]);

        expect(app(Heartbeats::class)->flush())->toBe(0);
    });

    it('still delivers when it cannot remember that it did', function (): void {
        Http::fake(['*' => Http::response([], 202)]);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('forever')->andThrow(new RuntimeException('read only'));

        schedule()->command('a')->hourly();
        finish(schedule()->command('a')->hourly());

        expect(app(Heartbeats::class)->flush())->toBe(1);
    });

    it('does nothing from the flush when switched off', function (): void {
        Http::fake();
        config()->set('flare-client.heartbeats.enabled', false);

        schedule()->command('a')->hourly();

        expect(app(Heartbeats::class)->flush())->toBe(0);

        Http::assertNothingSent();
    });

    it('does not let a failing heartbeat flush fail the run or the events', function (): void {
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        app()->bind(Heartbeats::class, fn () => throw new RuntimeException('cannot build'));

        app(Spool::class)->push(['event_id' => 'one']);

        $this->artisan('flare:flush')->expectsOutputToContain('Flushed 1 event(s).')->assertOk();
    });

    it('falls back to defaults for bad config', function (): void {
        config()->set('flare-client.heartbeats.path', '');
        config()->set('flare-client.heartbeats.batch_size', 'x');

        finish(schedule()->command('a'));

        expect(Storage::disk('local')->files('flare-heartbeats'))->toHaveCount(1);
    });

    it('survives a spool that cannot be listed or moved', function (): void {
        $spool = new Spool('local', 'flare-heartbeats');

        expect($spool->claim('flare-heartbeats/missing.jsonl'))->toBeNull();

        Storage::shouldReceive('disk')->andThrow(new RuntimeException('gone'));

        expect($spool->staleClaims(60))->toBe([])
            ->and($spool->claim('x'))->toBeNull();
    });

    it('does not report a spooled error through heartbeats', function (): void {
        Http::fake(['*' => Http::response([], 202)]);

        app(Reporter::class)->report(new RuntimeException('boom'), Source::Console);

        expect(spooledBeats())->toBe([]);
    });
});
