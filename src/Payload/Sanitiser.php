<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Payload;

use Illuminate\Http\UploadedFile;

/**
 * Removes anything that must never reach an error tracker.
 *
 * Three layers, deliberately redundant. A header deny-list catches the obvious
 * places; a key-name pattern applied at every depth catches nested input; and
 * value-shape matching catches the secret that happened to be stored in a
 * field called "data". The cost of a miss is a live credential sitting in a
 * database forever, so overlap is the point.
 */
class Sanitiser
{
    public const REDACTED = '[filtered]';

    /**
     * Shapes that are secrets whatever they are called.
     */
    private const VALUE_PATTERNS = [
        '/^Bearer\s+\S+/i',
        '/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/',          // JWT
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/^[A-Fa-f0-9]{32,}$/',                           // long hex: keys, hashes
        '/^(?:\d[ -]?){13,19}$/',                         // card-shaped
        '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/', // provider keys
    ];

    /**
     * Keys are normalised to strings on the way out, so anything array-shaped
     * can come in: request input, a decoded body, a log record's context.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public function scrubArray(array $input, int $depth = 0): array
    {
        if ($depth >= $this->maxDepth()) {
            return ['[truncated]' => true];
        }

        $clean = [];

        foreach ($input as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;

            if ($this->isSensitiveKey($name)) {
                $clean[$name] = self::REDACTED;

                continue;
            }

            $clean[$name] = $this->scrubValue($value, $depth);
        }

        return $clean;
    }

    public function scrubValue(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'name' => $value->getClientOriginalName(),
                'size' => $value->getSize(),
                'mime' => $value->getClientMimeType(),
            ];
        }

        if (is_array($value)) {
            return $this->scrubArray($value, $depth + 1);
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[object]';
    }

    public function scrubString(string $value): string
    {
        foreach (self::VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return self::REDACTED;
            }
        }

        return mb_substr($value, 0, $this->maxStringLength());
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, mixed>
     */
    public function scrubHeaders(array $headers): array
    {
        $denied = $this->deniedHeaders();
        $clean = [];

        foreach ($headers as $name => $value) {
            $key = mb_strtolower((string) $name);

            $clean[$key] = in_array($key, $denied, true)
                ? self::REDACTED
                : $this->scrubValue($value);
        }

        return $clean;
    }

    /**
     * Query strings get the same treatment as body input.
     *
     * Easy to forget, and password reset links put the token right there in
     * ?token=, which would otherwise be captured verbatim on every 500 that
     * happens to occur on a reset page.
     */
    public function scrubUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['query'])) {
            return mb_substr($url, 0, $this->maxStringLength());
        }

        parse_str($parts['query'], $query);

        $scrubbed = $this->scrubArray($query);

        $rebuilt = ($parts['scheme'] ?? 'http').'://'
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .'?'.http_build_query($scrubbed);

        return mb_substr($rebuilt, 0, $this->maxStringLength());
    }

    private function isSensitiveKey(string $key): bool
    {
        $pattern = config('flare-client.sanitise.key_pattern');

        if (is_string($pattern) && preg_match($pattern, $key) === 1) {
            return true;
        }

        $extra = config('flare-client.sanitise.extra_keys', []);

        if (! is_array($extra)) {
            return false;
        }

        foreach ($extra as $candidate) {
            if (is_string($candidate) && mb_strtolower($candidate) === mb_strtolower($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function deniedHeaders(): array
    {
        $headers = config('flare-client.sanitise.headers', []);

        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $header): string => mb_strtolower(is_string($header) ? $header : ''),
            $headers,
        ));
    }

    private function maxStringLength(): int
    {
        $value = config('flare-client.sanitise.max_string_length', 2000);

        return is_int($value) && $value > 0 ? $value : 2000;
    }

    private function maxDepth(): int
    {
        $value = config('flare-client.sanitise.max_depth', 6);

        return is_int($value) && $value > 0 ? $value : 6;
    }
}
