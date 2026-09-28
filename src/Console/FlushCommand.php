<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Console;

use Illuminate\Console\Command;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;

/**
 * Replays spooled events.
 *
 * Scheduled every minute. Every app in the estate runs a scheduler, but six of
 * them have no queue worker, which is exactly why delivery is built on the
 * scheduler rather than on a queued job.
 *
 * It runs in the foreground of schedule:run, so the app's other tasks that
 * minute wait for it. A run sends at most --batches requests, each held to the
 * HTTP timeout, which keeps that wait to seconds even with a full spool behind
 * it; whatever is left goes on the next run.
 */
class FlushCommand extends Command
{
    protected $signature = 'flare:flush
        {--limit=10 : Maximum spool files to drain in one run}
        {--batches=10 : Maximum batch requests to send in one run}';

    protected $description = 'Send spooled flare events that could not be delivered inline';

    public function handle(Spool $spool, Transport $transport): int
    {
        $files = $spool->files();

        if ($files === []) {
            $this->info('Nothing spooled.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $batchesLeft = max((int) $this->option('batches'), 1);
        $size = $this->batchSize();
        $sent = 0;

        foreach (array_slice($files, 0, max($limit, 1)) as $file) {
            // Stopped before the next file rather than rewriting it unchanged:
            // that would reset its age, which is how the doctor spots a flush
            // that is not running.
            if ($batchesLeft === 0) {
                break;
            }

            $events = $spool->read($file);

            if ($events === []) {
                $spool->forget($file);

                continue;
            }

            $sending = array_slice($events, 0, $batchesLeft * $size);
            $deferred = array_slice($events, count($sending));
            $batchesLeft -= (int) ceil(count($sending) / $size);

            $remaining = $this->drain($transport, $sending, $sent);

            $spool->rewrite($file, [...$remaining, ...$deferred]);

            if ($remaining !== []) {
                // flare stopped taking events part way through: unreachable,
                // busy, or deliberately shedding. Walking the rest of the spool
                // would only add load to something already struggling, and the
                // next run picks up exactly where this one stopped.
                $this->warn(sprintf('flare stopped accepting events, %d left spooled.', count($remaining) + count($deferred)));

                return self::SUCCESS;
            }
        }

        $this->info(sprintf('Flushed %d event(s).', $sent));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>> the events still undelivered
     */
    private function drain(Transport $transport, array $events, int &$sent): array
    {
        $size = $this->batchSize();

        foreach (array_chunk($events, $size) as $index => $chunk) {
            $result = $transport->sendBatch($chunk);

            $sent += $result->accepted;

            // Partial acceptance is the normal answer when flare is at its
            // ceiling. Treating it as full delivery, which is what a bare
            // "sent" verdict did, silently threw away everything past the
            // event flare stopped at.
            if ($result->accepted < count($chunk)) {
                return array_slice($events, $index * $size + $result->accepted);
            }
        }

        return [];
    }

    /**
     * @return int<1, max>
     */
    private function batchSize(): int
    {
        $value = config('flare-client.spool.batch_size', 50);

        return is_int($value) && $value > 0 ? $value : 50;
    }
}
