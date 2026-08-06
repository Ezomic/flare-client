<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one event, or decides what to do when it cannot.
 *
 * The rules, in the order they matter:
 *
 *   circuit open -> spool without attempting HTTP
 *   2xx          -> done
 *   429          -> honour Retry-After, DROP (flare is deliberately shedding;
 *                   replaying is exactly what it asked us not to do)
 *   413          -> DROP (permanently oversized, replay cannot fix it)
 *   anything else or a timeout -> spool and count a failure
 */
class Transport
{
    public function __construct(
        private readonly Spool $spool,
        private readonly CircuitBreaker $circuit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): Delivery
    {
        // Spool-only delivery exists for one installation: flare reporting to
        // itself. An inline post from flare is another ingest request, and an
        // ingest request that fails would report by making another one. The
        // re-entrancy guard is per process and cannot see across that hop.
        // Through the spool, the report is a file write and the flush runs in
        // its own process a minute later, where there is nothing to recurse.
        if ($this->spoolOnly()) {
            return $this->spool->push($payload) ? Delivery::Spooled : Delivery::Dropped;
        }

        if ($this->circuit->isOpen()) {
            $this->spool->push($payload);

            return Delivery::Spooled;
        }

        try {
            $response = $this->post('/api/ingest', $payload);
        } catch (Throwable) {
            $this->circuit->recordFailure();
            $this->spool->push($payload);

            return Delivery::Spooled;
        }

        return $this->interpret($response, $payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function sendBatch(array $events): BatchResult
    {
        if ($events === []) {
            return new BatchResult(Delivery::Sent, 0);
        }

        if ($this->circuit->isOpen()) {
            return new BatchResult(Delivery::Spooled, 0);
        }

        try {
            $response = $this->post('/api/ingest/batch', ['events' => $events]);
        } catch (Throwable) {
            $this->circuit->recordFailure();

            return new BatchResult(Delivery::Spooled, 0);
        }

        if ($response->status() === 429) {
            $this->circuit->muteFor($this->retryAfter($response));

            return new BatchResult(Delivery::Throttled, 0);
        }

        if ($response->successful()) {
            $this->circuit->recordSuccess();

            return new BatchResult(Delivery::Sent, $this->accepted($response, count($events)));
        }

        $this->circuit->recordFailure();

        return new BatchResult(Delivery::Spooled, 0);
    }

    /**
     * How many events flare says it took.
     *
     * A 202 with no count is the whole batch: the status is the answer and the
     * count is flare volunteering that it took fewer. Anything unusable is read
     * the same way, since inventing a smaller number would mean replaying
     * events that have already landed.
     */
    private function accepted(Response $response, int $sent): int
    {
        $accepted = $response->json('accepted');

        return is_int($accepted) && $accepted >= 0 ? min($accepted, $sent) : $sent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function interpret(Response $response, array $payload): Delivery
    {
        $status = $response->status();

        if ($status === 429) {
            $this->circuit->muteFor($this->retryAfter($response));

            return Delivery::Dropped;
        }

        if ($status === 413) {
            return Delivery::Dropped;
        }

        if ($response->successful()) {
            $this->circuit->recordSuccess();

            return Delivery::Sent;
        }

        $this->circuit->recordFailure();
        $this->spool->push($payload);

        return Delivery::Spooled;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): Response
    {
        return Http::withHeaders([
            'X-Flare-Key' => $this->key(),
            'Accept' => 'application/json',
        ])
            ->connectTimeout($this->floatConfig('flare-client.connect_timeout', 0.5))
            ->timeout($this->floatConfig('flare-client.timeout', 1.5))
            // Retries are the spool's job, not the request's: retrying inline
            // multiplies the latency this whole design exists to bound.
            ->post($this->url().$path, $body);
    }

    /**
     * Only the inline path is affected. The flush is the delivery this mode
     * defers to, so switching it off as well would mean nothing is ever sent.
     */
    public function spoolOnly(): bool
    {
        return config('flare-client.delivery', 'inline') === 'spool';
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : 60;
    }

    private function url(): string
    {
        $url = config('flare-client.url', '');

        return rtrim(is_string($url) ? $url : '', '/');
    }

    private function key(): string
    {
        $key = config('flare-client.key');

        return is_string($key) ? $key : '';
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
