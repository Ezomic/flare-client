<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Transport;

/**
 * Proves the wiring in one command.
 *
 * Rolling flare out to seventeen more apps is a thirty second check per app
 * with this, and a hope without it.
 */
class TestCommand extends Command
{
    protected $signature = 'flare:test';

    protected $description = 'Send a deliberate test exception to flare and report what happened';

    public function handle(Reporter $reporter, Transport $transport): int
    {
        $this->line('Reporting to: '.$this->stringConfig('flare-client.url'));
        $this->line('Key present:  '.(is_string(config('flare-client.key')) && config('flare-client.key') !== '' ? 'yes' : 'no'));
        $this->line('Enabled:      '.(config('flare-client.enabled') === true ? 'yes' : 'no'));
        $this->line('Delivery:     '.($transport->spoolOnly() ? 'spool only' : 'inline'));
        $this->newLine();

        $delivery = $reporter->report(
            new RuntimeException('flare:test from '.$this->stringConfig('app.name')),
            Source::Console,
            ['command' => 'flare:test'],
        );

        return match ($delivery) {
            Delivery::Sent => $this->succeeded('Delivered. flare has the event.'),
            // Spooling is the expected outcome under spool-only delivery, not
            // a symptom of flare being unreachable.
            Delivery::Spooled => $transport->spoolOnly()
                ? $this->succeeded('Spooled. This app delivers through flare:flush, which will send it within the minute.')
                : $this->failed('Could not reach flare; the event was spooled. It will be retried by flare:flush.'),
            Delivery::Dropped => $this->failed('flare refused the event (rate limited or too large). Nothing was spooled.'),
            Delivery::Throttled => $this->failed('flare is rate limiting this app.'),
            Delivery::Skipped => $this->failed('Nothing was sent. Check FLARE_ENABLED, FLARE_KEY and the console source toggle.'),
        };
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    private function succeeded(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
