<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Console;

use Illuminate\Console\Command;
use Thijssensoftware\FlareClient\Heartbeat\Heartbeats;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;
use Throwable;

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
 *
 * The heartbeats that follow have a budget of their own, much smaller
 * (heartbeats.max_batches and heartbeats.max_seconds), because --batches
 * applies to events only and a ping is never worth holding the scheduler for.
 */
class FlushCommand extends Command
{
    /**
     * Well under flare's 256 KiB body limit, as the ingest contract asks.
     */
    private const BATCH_BYTES = 200_000;

    protected $signature = 'flare:flush
        {--limit=10 : Maximum spool files to drain in one run}
        {--batches=10 : Maximum batch requests to send in one run}';

    protected $description = 'Send spooled flare events that could not be delivered inline';

    public function handle(Spool $spool, Transport $transport): int
    {
        $status = $this->flushEvents($spool, $transport);

        // After the events, and unable to fail the run: errors matter more than
        // heartbeats, and nothing about a heartbeat may abort schedule:run.
        try {
            app(Heartbeats::class)->flush();
        } catch (Throwable) {
            // Spooled, so the next minute tries again.
        }

        return $status;
    }

    private function flushEvents(Spool $spool, Transport $transport): int
    {
        $files = $spool->files();

        if ($files === []) {
            $this->info('Nothing spooled.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $batchesLeft = max((int) $this->option('batches'), 1);
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

            $chunks = $this->chunks($events);
            $sending = array_slice($chunks, 0, $batchesLeft);
            $deferred = array_merge(...array_slice($chunks, count($sending)));
            $batchesLeft -= count($sending);

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
     * @param  array<int, array<int, array<string, mixed>>>  $chunks
     * @return array<int, array<string, mixed>> the events still undelivered
     */
    private function drain(Transport $transport, array $chunks, int &$sent): array
    {
        foreach (array_values($chunks) as $index => $chunk) {
            $result = $transport->sendBatch($chunk);

            // A single event flare refuses as too large stays too large, and
            // keeping it would block everything spooled behind it.
            if ($result->delivery === Delivery::Dropped) {
                continue;
            }

            $sent += $result->accepted;

            // Partial acceptance is the normal answer when flare is at its
            // ceiling. Treating it as full delivery, which is what a bare
            // "sent" verdict did, silently threw away everything past the
            // event flare stopped at.
            if ($result->accepted < count($chunk)) {
                return [
                    ...array_slice($chunk, $result->accepted),
                    ...array_merge(...array_slice($chunks, $index + 1)),
                ];
            }
        }

        return [];
    }

    /**
     * Splits events into batches by count and by encoded size.
     *
     * flare refuses a batch body over 256 KiB with a 413, and one event may be
     * close to that on its own, so a count alone sends batches that can never
     * land. Every batch holds at least one event.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function chunks(array $events): array
    {
        $size = $this->batchSize();
        $chunks = [];
        $chunk = [];
        $bytes = 0;

        foreach ($events as $event) {
            $eventBytes = strlen((string) json_encode($event)) + 1;

            if ($chunk !== [] && (count($chunk) >= $size || $bytes + $eventBytes > self::BATCH_BYTES)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $event;
            $bytes += $eventBytes;
        }

        $chunks[] = $chunk;

        return $chunks;
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
