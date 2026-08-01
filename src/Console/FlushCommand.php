<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Console;

use Illuminate\Console\Command;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;

/**
 * Replays spooled events.
 *
 * Scheduled every minute. Every app in the estate runs a scheduler, but six of
 * them have no queue worker, which is exactly why delivery is built on the
 * scheduler rather than on a queued job.
 */
class FlushCommand extends Command
{
    protected $signature = 'flare:flush {--limit=10 : Maximum spool files to drain in one run}';

    protected $description = 'Send spooled flare events that could not be delivered inline';

    public function handle(Spool $spool, Transport $transport): int
    {
        $files = $spool->files();

        if ($files === []) {
            $this->info('Nothing spooled.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $sent = 0;

        foreach (array_slice($files, 0, max($limit, 1)) as $file) {
            $events = $spool->read($file);

            if ($events === []) {
                $spool->forget($file);

                continue;
            }

            $remaining = $this->drain($transport, $events, $sent);

            if ($remaining === $events) {
                // Nothing moved: flare is still unreachable or shedding, so
                // stop rather than walking the rest of the spool for nothing.
                $this->warn('flare is unreachable, leaving the spool in place.');

                return self::SUCCESS;
            }

            $spool->rewrite($file, $remaining);
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
            if ($transport->sendBatch($chunk) !== Delivery::Sent) {
                return array_values(array_slice($events, $index * $size));
            }

            $sent += count($chunk);
        }

        return [];
    }

    private function batchSize(): int
    {
        $value = config('flare-client.spool.batch_size', 50);

        return is_int($value) && $value > 0 ? $value : 50;
    }
}
