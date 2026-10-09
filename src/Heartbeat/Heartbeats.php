<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Heartbeat;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;
use Throwable;

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

    private readonly Spool $spool;

    public function __construct(private readonly Transport $transport, private readonly Schedule $schedule)
    {
        $this->spool = new Spool('local', $this->path());
    }

    public function record(Event $task, bool $succeeded): void
    {
        if (config('flare-client.heartbeats.enabled', true) !== true || config('flare-client.enabled', true) !== true) {
            return;
        }

        $key = TaskKey::for($task);

        if ($this->ignored($key)) {
            return;
        }

        $this->spool->push([
            'task' => $key,
            'outcome' => $succeeded ? 'success' : 'failure',
            'at' => now()->toIso8601String(),
            'expression' => $task->getExpression(),
            'timezone' => $this->timezone($task),
        ]);
    }

    /**
     * Sends everything spooled, with the schedule attached when it has changed
     * since flare last accepted it.
     *
     * @return int the number of pings delivered
     */
    public function flush(): int
    {
        if (config('flare-client.heartbeats.enabled', true) !== true || config('flare-client.enabled', true) !== true) {
            return 0;
        }

        $schedule = $this->expected();
        $hash = hash('sha256', (string) json_encode($schedule));
        $pending = $this->hashChanged($hash) ? $schedule : [];
        $sent = 0;

        foreach ($this->spool->files() as $file) {
            $beats = $this->spool->read($file);

            foreach (array_chunk($beats, $this->batchSize()) as $index => $chunk) {
                if (! $this->transport->sendHeartbeats($chunk, $pending)) {
                    $this->spool->rewrite($file, array_slice($beats, $index * $this->batchSize()));

                    return $sent;
                }

                $sent += count($chunk);
                $this->remember($pending !== [], $hash);
                $pending = [];
            }

            $this->spool->forget($file);
        }

        if ($pending !== [] && $this->transport->sendHeartbeats([], $pending)) {
            $this->remember(true, $hash);
        }

        return $sent;
    }

    /**
     * What this app expects to run: every scheduled task that would run in this
     * environment, except the flush that carries these pings.
     *
     * @return array<int, array{task: string, expression: string, timezone: string}>
     */
    private function expected(): array
    {
        $tasks = [];

        foreach ($this->schedule->events() as $event) {
            $key = TaskKey::for($event);

            if ($this->ignored($key) || ! $event->runsInEnvironment(app()->environment())) {
                continue;
            }

            $tasks[$key] = [
                'task' => $key,
                'expression' => $event->getExpression(),
                'timezone' => $this->timezone($event),
            ];
        }

        ksort($tasks);

        return array_values($tasks);
    }

    private function ignored(string $key): bool
    {
        return str_starts_with($key, 'flare:');
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
        $value = config('flare-client.heartbeats.batch_size', 200);

        return is_int($value) && $value > 0 ? $value : 200;
    }

    private function path(): string
    {
        $path = config('flare-client.heartbeats.path', 'flare-heartbeats');

        return is_string($path) && $path !== '' ? $path : 'flare-heartbeats';
    }
}
