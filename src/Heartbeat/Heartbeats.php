<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Heartbeat;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;
use Throwable;
use WeakMap;

/**
 * Pings flare when a scheduled task finishes, so a task that stops running
 * shows up as the absence of its ping.
 *
 * Recording is a file append and nothing else. Nineteen apps running
 * schedule:run every minute with several tasks each would be thousands of
 * requests an hour if each ping were posted, so they are batched by the flush.
 */
class Heartbeats
{
    private const SCHEDULE_HASH = 'flare-client:schedule-hash';

    private const BACKOFF = 'flare-client:heartbeat-backoff-until';

    private readonly Spool $spool;

    /** @var WeakMap<Event, true> */
    private WeakMap $recorded;

    private bool $warnedUnnamed = false;

    public function __construct(private readonly Transport $transport, private readonly Schedule $schedule)
    {
        $this->spool = new Spool('local', $this->path());
        $this->recorded = new WeakMap;
    }

    /**
     * A task is about to run: forget that it was recorded, because the same
     * event object runs again under schedule:work.
     */
    public function starting(Event $task): void
    {
        unset($this->recorded[$task]);
    }

    /**
     * One beat per run. A failing command raises both a finished and a failed
     * event, and the first to arrive is the one that counts.
     */
    public function record(Event $task, bool $succeeded, bool $background = false): void
    {
        if (! $this->enabled() || (! $background && isset($this->recorded[$task]))) {
            return;
        }

        // Skipped because the previous run still holds its mutex: nothing ran,
        // so there is nothing to report, and a success here would hide a task
        // that is stuck.
        if ($task->skippedBecauseOverlapping) {
            return;
        }

        $key = TaskKey::for($task);

        if ($key === null) {
            $this->warnUnnamed();

            return;
        }

        $this->recorded[$task] = true;

        if ($this->ignored($key)) {
            return;
        }

        $this->spool->push([
            'task' => $key,
            'description' => TaskKey::description($task),
            'outcome' => $succeeded ? 'success' : 'failure',
            'at' => now()->toIso8601String(),
            'timezone' => $this->timezone($task),
            ...$this->expression($task),
        ]);
    }

    /**
     * Sends everything spooled, with the schedule attached when it has changed
     * since flare last accepted it.
     *
     * Bounded: a handful of batches and a time budget per run, so a long
     * backlog (or a slow flare) cannot hold up the rest of schedule:run. What
     * is not sent stays spooled for the next minute.
     *
     * @return int the number of pings delivered
     */
    public function flush(): int
    {
        if (! $this->enabled() || $this->backingOff()) {
            return 0;
        }

        $schedule = $this->expected();
        $hash = hash('sha256', (string) json_encode(array_map(
            fn (array $task): array => [$task['task'], $task['expression'], $task['timezone']],
            $schedule,
        )));
        $pending = $this->hashChanged($hash) ? $schedule : [];
        $run = new Run(microtime(true), $this->intConfig('max_batches', 3), $this->intConfig('max_seconds', 5));
        $sent = 0;

        foreach ($this->files() as [$path, $claimed]) {
            if ($run->spent()) {
                break;
            }

            $file = $claimed ? $path : $this->spool->claim($path);

            if ($file === null) {
                continue;
            }

            $beats = $this->spool->read($file);

            foreach (array_chunk($beats, $this->batchSize()) as $index => $chunk) {
                $result = $run->spent() ? null : $this->transport->sendHeartbeats($chunk, $pending);

                if ($result === null || ! $result->finished) {
                    $this->requeue(array_slice($beats, $index * $this->batchSize()));
                    $this->spool->forget($file);
                    $this->backOff($result === null ? 0 : $result->backoff);

                    return $sent;
                }

                $run->batch();
                $sent += count($chunk);
                $this->remember($pending !== [], $hash);
                $pending = [];
            }

            $this->spool->forget($file);
        }

        if ($pending !== [] && ! $run->spent()) {
            $result = $this->transport->sendHeartbeats([], $pending);

            if ($result->finished) {
                $this->remember(true, $hash);
            } else {
                $this->backOff($result->backoff);
            }
        }

        return $sent;
    }

    /**
     * The files to send: claims whose sender died, then the live files. A live
     * file is claimed (renamed) only when the loop reaches it, so that an early
     * stop leaves later files as ordinary spool files and not as claims no one
     * will finish for ten minutes.
     *
     * @return array<int, array{string, bool}>
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->spool->staleClaims($this->intConfig('stale_claim_seconds', 600)) as $claim) {
            $files[] = [$claim, true];
        }

        foreach ($this->spool->files() as $file) {
            $files[] = [$file, false];
        }

        return $files;
    }

    /**
     * @param  array<int, array<string, mixed>>  $beats
     */
    private function requeue(array $beats): void
    {
        foreach ($beats as $beat) {
            $this->spool->push($beat);
        }
    }

    /**
     * What this app expects to run: every scheduled task that would run in this
     * environment, except the flush that carries these pings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function expected(): array
    {
        $tasks = [];

        foreach ($this->schedule->events() as $event) {
            $key = TaskKey::for($event);

            if ($key === null || $this->ignored($key) || ! $event->runsInEnvironment(app()->environment())) {
                continue;
            }

            $tasks[$key] = [
                'task' => $key,
                'description' => TaskKey::description($event),
                'timezone' => $this->timezone($event),
                ...$this->expression($event),
            ];
        }

        ksort($tasks);

        return array_values($tasks);
    }

    /**
     * The expression to report, or none and an explicit marker for a task whose
     * cron line is only an upper bound. The marker is what lets flare stop
     * alerting on a task that has just been given a filter; an older flare
     * ignores the extra field.
     *
     * @return array{expression: string|null, scheduled?: false}
     */
    private function expression(Event $event): array
    {
        return $this->scheduled($event)
            ? ['expression' => $event->getExpression()]
            : ['expression' => null, 'scheduled' => false];
    }

    /**
     * Whether the cron expression says when the task really runs. A filter
     * (when, between) or a reject (skip) the app wrote decides at run time, so
     * the expression is only an upper bound and flare must not alert on its
     * silence.
     *
     * withoutOverlapping() is not one of those: it adds a skip of its own, the
     * mutex check, to every task that uses it, which is nearly every task.
     * Counting it made all of them unscheduled.
     */
    private function scheduled(Event $event): bool
    {
        $filters = (new ReflectionProperty(Event::class, 'filters'))->getValue($event);
        $rejects = (new ReflectionProperty(Event::class, 'rejects'))->getValue($event);

        if (is_array($filters) && $filters !== []) {
            return false;
        }

        return ! is_array($rejects) || $this->userRejects($event, $rejects) === 0;
    }

    /**
     * How many of the rejects are the app's own.
     *
     * The framework's one is a closure created inside withoutOverlapping(), so
     * it lives in the file that method is defined in, whichever Laravel
     * version and whether on a command or a callback. Telling it apart by
     * where it was written, and not by counting calls, keeps a skip() the app
     * added next to it counted, and withoutOverlapping() called twice (two
     * identical framework closures) from counting at all.
     *
     * @param  array<array-key, mixed>  $rejects
     */
    private function userRejects(Event $event, array $rejects): int
    {
        $own = array_unique([
            (new ReflectionMethod(Event::class, 'withoutOverlapping'))->getFileName(),
            (new ReflectionMethod($event, 'withoutOverlapping'))->getFileName(),
        ]);

        return count(array_filter(
            $rejects,
            fn (mixed $reject): bool => ! $reject instanceof Closure
                || ! in_array((new ReflectionFunction($reject))->getFileName(), $own, true),
        ));
    }

    private function ignored(string $key): bool
    {
        return str_starts_with($key, 'flare:');
    }

    private function warnUnnamed(): void
    {
        if ($this->warnedUnnamed) {
            return;
        }

        $this->warnedUnnamed = true;

        try {
            Log::debug('flare heartbeats: a scheduled callback has no name and is not monitored, give it ->name()');
        } catch (Throwable) {
            // Not worth failing a task over.
        }
    }

    private function timezone(Event $event): string
    {
        $timezone = $event->timezone;
        $name = $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone;

        if ($name !== '') {
            return $name;
        }

        $app = config('app.timezone', 'UTC');

        return is_string($app) ? $app : 'UTC';
    }

    private function enabled(): bool
    {
        return config('flare-client.heartbeats.enabled', true) === true
            && config('flare-client.enabled', true) === true;
    }

    private function backingOff(): bool
    {
        try {
            $until = Cache::get(self::BACKOFF);
        } catch (Throwable) {
            return false;
        }

        return is_int($until) && $until > time();
    }

    private function backOff(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        try {
            Cache::put(self::BACKOFF, time() + $seconds, $seconds);
        } catch (Throwable) {
            // Tried again next minute instead.
        }
    }

    private function hashChanged(string $hash): bool
    {
        try {
            return Cache::get(self::SCHEDULE_HASH) !== $hash;
        } catch (Throwable) {
            return false;
        }
    }

    private function remember(bool $delivered, string $hash): void
    {
        if (! $delivered) {
            return;
        }

        try {
            Cache::forever(self::SCHEDULE_HASH, $hash);
        } catch (Throwable) {
            // Sent again next time, which flare treats as a no-op.
        }
    }

    /**
     * @return int<1, max>
     */
    private function batchSize(): int
    {
        return $this->intConfig('batch_size', 200);
    }

    /**
     * @return int<1, max>
     */
    private function intConfig(string $key, int $default): int
    {
        $value = config('flare-client.heartbeats.'.$key, $default);

        return max(1, is_int($value) && $value > 0 ? $value : $default);
    }

    private function path(): string
    {
        $path = config('flare-client.heartbeats.path', 'flare-heartbeats');

        return is_string($path) && $path !== '' ? $path : 'flare-heartbeats';
    }
}
