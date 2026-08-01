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
    public function sendBatch(array $events): Delivery
    {
        if ($events === []) {
            return Delivery::Sent;
        }

        if ($this->circuit->isOpen()) {
            return Delivery::Spooled;
        }

        try {
            $response = $this->post('/api/ingest/batch', ['events' => $events]);
        } catch (Throwable) {
            $this->circuit->recordFailure();

            return Delivery::Spooled;
        }

        if ($response->status() === 429) {
            $this->circuit->muteFor($this->retryAfter($response));

            return Delivery::Throttled;
        }

        if ($response->successful()) {
            $this->circuit->recordSuccess();

            return Delivery::Sent;
        }

        $this->circuit->recordFailure();

        return Delivery::Spooled;
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
