<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as FoundationHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Thijssensoftware\FlareClient\Console\FlushCommand;
use Thijssensoftware\FlareClient\Console\TestCommand;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Support\Runtime;
use Throwable;

class FlareClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flare-client.php', 'flare-client');

        $this->app->singleton(Reporter::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/flare-client.php' => config_path('flare-client.php'),
        ], 'flare-client-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class, TestCommand::class]);
        }

        $this->registerExceptionReporting();
        $this->registerJobFailures();
        $this->registerScheduleFailures();
        $this->registerConsoleFailures();
        $this->scheduleFlush();
    }

    /**
     * Hooks the framework handler's reportable callback.
     *
     * Apps that swap the handler for their own get nothing here and must call
     * the reporter themselves; that is documented rather than worked around,
     * because guessing at a custom handler's shape is worse than being clear.
     */
    private function registerExceptionReporting(): void
    {
        $this->app->booted(function (): void {
            try {
                $handler = $this->app->make(ExceptionHandler::class);
            } catch (Throwable) {
                return;
            }

            if (! $handler instanceof FoundationHandler) {
                return;
            }

            $handler->reportable(function (Throwable $e): void {
                $this->reporter()->report($e, $this->currentSource());
            });
        });
    }

    private function registerJobFailures(): void
    {
        Event::listen(function (JobFailed $event): void {
            $this->reporter()->report($event->exception, Source::Job, [
                'job_class' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $event->job->attempts(),
            ]);
        });
    }

    /**
     * The biggest silent hole in the estate: cron pipes schedule:run to
     * /dev/null on most lines, so a task that has been failing for three weeks
     * looks exactly like one that is working.
     */
    private function registerScheduleFailures(): void
    {
        Event::listen(function (ScheduledTaskFailed $event): void {
            $this->reporter()->report($event->exception, Source::Schedule, [
                'command' => $event->task->getSummaryForDisplay(),
                'schedule_expression' => $event->task->getExpression(),
            ]);
        });

        // A background task runs in its own process, so a non-zero exit is all
        // that comes back: there is no exception to forward, only the code.
        Event::listen(function (ScheduledBackgroundTaskFinished $event): void {
            if (($event->task->exitCode ?? 0) === 0) {
                return;
            }

            $summary = $event->task->getSummaryForDisplay();

            $this->reporter()->report(
                new RuntimeException(sprintf(
                    'Scheduled task [%s] exited with code %d',
                    $summary,
                    (int) $event->task->exitCode,
                )),
                Source::Schedule,
                [
                    'command' => $summary,
                    'schedule_expression' => $event->task->getExpression(),
                    'exit_code' => (int) $event->task->exitCode,
                ],
            );
        });
    }

    /**
     * Note for anyone testing this: Laravel skips rerouting the Symfony
     * console events to their Laravel counterparts while running unit tests
     * (see Foundation\Console\Kernel's constructor), so CommandFinished never
     * fires without the WithConsoleEvents trait. Production is unaffected.
     */
    private function registerConsoleFailures(): void
    {
        Event::listen(function (CommandFinished $event): void {
            if ($event->exitCode === 0 || $event->command === null) {
                return;
            }

            // flare's own commands must not report their own non-zero exits,
            // or a flare:test that legitimately fails becomes an error report
            // about itself.
            if (str_starts_with($event->command, 'flare:')) {
                return;
            }

            $this->reporter()->report(
                new RuntimeException(sprintf('Command [%s] exited with code %d', $event->command, $event->exitCode)),
                Source::Console,
                ['command' => $event->command, 'exit_code' => $event->exitCode],
            );
        });
    }

    private function scheduleFlush(): void
    {
        $this->app->booted(function (): void {
            if (config('flare-client.spool.enabled', true) !== true) {
                return;
            }

            try {
                $schedule = $this->app->make(Schedule::class);
            } catch (Throwable) {
                return;
            }

            $schedule->command(FlushCommand::class)
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground();
        });
    }

    private function reporter(): Reporter
    {
        return $this->app->make(Reporter::class);
    }

    /**
     * An exception reported through the handler could be from a request or
     * from a command; the source decides which group it lands in, so guessing
     * wrong would merge two genuinely different bugs.
     */
    private function currentSource(): Source
    {
        return Runtime::isHttpRequest() ? Source::Http : Source::Console;
    }
}
