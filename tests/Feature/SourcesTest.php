<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Laravel skips rerouting the Symfony console events while running unit tests
// (Foundation\Console\Kernel's constructor), so CommandFinished never fires
// without this. It has to be applied at file level; chaining ->uses() onto an
// individual test does not register the trait.
uses(WithConsoleEvents::class);

it('reports a failed queue job with the job it was running', function (): void {
    $sent = null;

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response([], 202);
    });

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SendInvoice');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(3);
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobFailed('database', $job, new RuntimeException('Job blew up')));

    expect($sent)->not->toBeNull()
        ->and($sent['source'])->toBe('job')
        ->and($sent['origin']['job_class'])->toBe('App\Jobs\SendInvoice')
        ->and($sent['origin']['attempts'])->toBe(3)
        ->and($sent['origin']['queue'])->toBe('default');
});

it('reports a failing scheduled task with the command and its cron expression', function (): void {
    $sent = null;

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response([], 202);
    });

    // Driven through the framework's own event rather than by reaching into
    // the schedule's callbacks: apps register their tasks after this package
    // boots, so anything that iterates $schedule->events() at boot time sees
    // an empty list in a real app.
    $task = app(Schedule::class)->command('inspire')->dailyAt('03:20');

    event(new ScheduledTaskFailed($task, new RuntimeException('Task blew up')));

    expect($sent)->not->toBeNull()
        ->and($sent['source'])->toBe('schedule')
        ->and($sent['origin']['command'])->toContain('inspire')
        ->and($sent['origin']['schedule_expression'])->toBe('20 3 * * *')
        ->and($sent['exception']['message'])->toBe('Task blew up');
});

it('reports a background scheduled task that exits non-zero', function (): void {
    $sent = null;

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response([], 202);
    });

    $task = app(Schedule::class)->command('inspire')->everyMinute()->runInBackground();
    $task->exitCode = 3;

    event(new ScheduledBackgroundTaskFinished($task));

    expect($sent)->not->toBeNull()
        ->and($sent['source'])->toBe('schedule')
        ->and($sent['origin']['exit_code'])->toBe(3);
});

it('says nothing about a background scheduled task that succeeds', function (): void {
    Http::fake();

    $task = app(Schedule::class)->command('inspire')->everyMinute()->runInBackground();
    $task->exitCode = 0;

    event(new ScheduledBackgroundTaskFinished($task));

    Http::assertNothingSent();
});

it('reports a command that exits non-zero', function (): void {
    $sent = null;

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response([], 202);
    });

    Artisan::command('probe:fail', fn (): int => 1);

    $this->artisan('probe:fail');

    expect($sent)->not->toBeNull()
        ->and($sent['source'])->toBe('console')
        ->and($sent['origin']['command'])->toBe('probe:fail')
        ->and($sent['origin']['exit_code'])->toBe(1);
});

it('says nothing about a command that succeeds', function (): void {
    Http::fake();

    Artisan::command('probe:ok', fn (): int => 0);

    $this->artisan('probe:ok');

    Http::assertNothingSent();
});

it('does not report its own commands failing', function (): void {
    Http::fake();

    // Without this guard a flare:test that legitimately cannot reach flare
    // becomes an error report about itself, which cannot be delivered either.
    config()->set('flare-client.key', null);

    $this->artisan('flare:test')->assertFailed();

    Http::assertNothingSent();
});

it('reports an uncaught http exception through the handler', function (): void {
    $sent = null;

    Http::fake(function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response([], 202);
    });

    Route::get('/blows-up', function (): void {
        throw new RuntimeException('Boom from a request');
    });

    $this->get('/blows-up');

    expect($sent)->not->toBeNull()
        ->and($sent['source'])->toBe('http')
        ->and($sent['exception']['message'])->toBe('Boom from a request')
        ->and($sent['request']['method'])->toBe('GET');
});

it('confirms delivery through flare:test', function (): void {
    Http::fake(['*' => Http::response(['id' => 'abc'], 202)]);

    $this->artisan('flare:test')
        ->expectsOutputToContain('Delivered.')
        ->assertOk();
});

it('reports through flare:test that the event was spooled when flare is down', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    $this->artisan('flare:test')
        ->expectsOutputToContain('spooled')
        ->assertFailed();
});
