<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Support;

use Illuminate\Contracts\Queue\Job;

/**
 * What the failed job was actually doing.
 *
 * "SendInvoice failed again" is a group you have to reproduce. "SendInvoice
 * failed for invoice 4821" is one you can act on, and the queue payload has
 * carried that all along.
 *
 * The one thing this must never do is unserialize the job. The payload holds
 * the command as a serialised object, and rebuilding it would run application
 * code, including __wakeup and any model resolution behind it, inside the
 * error path of a process that has already failed. An error tracker that
 * executes the thing it is reporting on is a worse bug than the one it is
 * describing.
 */
final class JobContext
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Job $job): array
    {
        $payload = $job->payload();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return array_filter([
            'job_uuid' => self::string($payload['uuid'] ?? null),
            'job_name' => self::string($payload['displayName'] ?? null),
            'command' => self::string($data['commandName'] ?? null),
            'max_tries' => self::int($payload['maxTries'] ?? null),
            'timeout' => self::int($payload['timeout'] ?? null),
            'data' => self::data($data),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The parts of the payload that are already decoded.
     *
     * Queued listeners, mail and notifications put plain values here, and jobs
     * dispatched the old way put their whole argument list here. Anything that
     * is only reachable inside the serialised command is deliberately left
     * where it is.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function data(array $data): ?array
    {
        unset($data['command'], $data['commandName']);

        $plain = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value) || is_array($value) || $value === null) {
                $plain[(string) $key] = $value;
            }
        }

        return $plain === [] ? null : $plain;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
