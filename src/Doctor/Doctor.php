<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Doctor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Thijssensoftware\FlareClient\Transport\Spool;
use Throwable;

/**
 * Checks whether an install will actually deliver anything.
 *
 * `flare:test` proves one event can be sent right now, which is not the same
 * question. The rollout produced two installs that passed it and were wrong:
 * one with no scheduler, so nothing would ever drain the spool, and one whose
 * round trip to flare was longer than the timeout it was configured with, so
 * half of its reports failed inline and arrived a minute late instead.
 *
 * Both were found by accident. Seventeen apps is too many to find by accident.
 */
class Doctor
{
    public function __construct(
        private readonly Application $app,
        private readonly Spool $spool,
    ) {}

    /**
     * @return array<int, Finding>
     */
    public function run(): array
    {
        return [
            ...$this->configuration(),
            $this->reachability(),
            ...$this->delivery(),
            ...$this->spoolHealth(),
        ];
    }

    /**
     * @return array<int, Finding>
     */
    private function configuration(): array
    {
        $key = config('flare-client.key');
        $url = config('flare-client.url');

        return [
            is_string($key) && $key !== ''
                ? Finding::ok('key', 'present')
                : Finding::fail('key', 'FLARE_KEY is not set, so nothing is sent and nothing says so'),

            is_string($url) && $url !== ''
                ? Finding::ok('url', $url)
                : Finding::fail('url', 'FLARE_URL is not set'),

            config('flare-client.enabled') === true
                ? Finding::ok('enabled', 'reporting is on')
                : Finding::warn('enabled', 'FLARE_ENABLED is off, so this app reports nothing'),
        ];
    }

    /**
     * Times a real round trip against the budget the client is given.
     *
     * flare's health endpoint costs what an ingest costs: dns, tls, nginx and
     * a Laravel boot. Comparing it against the configured timeout is what
     * turns "it works" into "it works with room to spare", which is the
     * difference the tracker install fell through.
     */
    private function reachability(): Finding
    {
        $url = $this->url();
        $timeout = $this->timeout();
        $started = microtime(true);

        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($url.'/health');
        } catch (Throwable $e) {
            return Finding::fail('reachable', 'cannot reach flare: '.$e->getMessage());
        }

        $elapsed = microtime(true) - $started;

        if (! $response->successful()) {
            return Finding::fail('reachable', sprintf('flare answered %d', $response->status()));
        }

        // Under spool delivery the timeout buys nothing a user waits for: the
        // event is a file write, and the round trip happens later from cron,
        // where a slow one costs nobody anything. Reporting the number is
        // useful; warning about it would be crying wolf at an app that has
        // already done the thing the warning would ask for.
        if ($this->spoolOnly()) {
            return Finding::ok('reachable', sprintf('%.2fs round trip, paid by the flush rather than a request', $elapsed));
        }

        $detail = sprintf('%.2fs round trip against a %.2fs timeout', $elapsed, $timeout);

        // Half the budget is the line: below it a slow moment still fits,
        // above it an ordinary one already does not.
        return $elapsed > $timeout / 2
            ? Finding::warn('reachable', $detail.', which is too little room. Consider FLARE_DELIVERY=spool')
            : Finding::ok('reachable', $detail);
    }

    private function url(): string
    {
        $url = config('flare-client.url', '');

        return rtrim(is_string($url) ? $url : '', '/');
    }

    private function spoolOnly(): bool
    {
        return config('flare-client.delivery', 'inline') === 'spool';
    }

    private function timeout(): float
    {
        $timeout = config('flare-client.timeout', 1.5);

        return is_numeric($timeout) ? (float) $timeout : 1.5;
    }

    /**
     * @return array<int, Finding>
     */
    private function delivery(): array
    {
        $spoolOnly = $this->spoolOnly();
        $scheduled = $this->flushIsScheduled();

        $mode = Finding::ok('delivery', $spoolOnly ? 'spool only' : 'inline');

        if ($scheduled) {
            return [$mode, Finding::ok('flush', 'flare:flush is on the schedule')];
        }

        // Inline delivery still works without the flush, which is exactly why
        // its absence goes unnoticed until flare is down and the events that
        // should have been retried are sitting on disk instead.
        return [$mode, $spoolOnly
            ? Finding::fail('flush', 'flare:flush is not scheduled and delivery is spool only, so nothing will ever be sent')
            : Finding::warn('flush', 'flare:flush is not scheduled, so anything undeliverable will spool and stay there'),
        ];
    }

    private function flushIsScheduled(): bool
    {
        try {
            $schedule = $this->app->make(Schedule::class);
        } catch (Throwable) {
            return false;
        }

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', 'flare:flush')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Finding>
     */
    private function spoolHealth(): array
    {
        if (config('flare-client.spool.enabled', true) !== true) {
            return [Finding::warn('spool', 'spooling is off, so an undeliverable event is a lost one')];
        }

        $files = $this->spool->files();

        if ($files === []) {
            return [Finding::ok('spool', 'empty')];
        }

        $events = 0;

        foreach ($files as $file) {
            $events += count($this->spool->read($file));
        }

        $oldest = $this->ageOfOldest($files[0]);
        $detail = sprintf('%d event(s) waiting in %d file(s)', $events, count($files));

        // A spool is a buffer, not a queue. Anything sitting in it for longer
        // than a few flush intervals means the flush is not running, whatever
        // the schedule says it should be doing.
        return [$oldest > 300
            ? Finding::fail('spool', $detail.sprintf(', oldest %d minutes old: the flush is not running', (int) ($oldest / 60)))
            : Finding::ok('spool', $detail),
        ];
    }

    private function ageOfOldest(string $file): int
    {
        try {
            return time() - $this->spool->lastModified($file);
        } catch (Throwable) {
            return 0;
        }
    }
}
