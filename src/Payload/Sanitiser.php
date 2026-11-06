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

    private const PERSONAL_KEY = '/(e-?mail|phone|mobile|telephone|(?:^|[_-])tel$|first.?name|last.?name|full.?name|sur.?name|user.?name|^name$|address|birth|postcode|postal)/i';

    private const QUERY_KEY = '/^(?:code|auth.?code|verification.?code)$/i';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/';

    private const BEARER = '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i';

    private const ASSIGNED_SECRET = '/\b((?:access[_-]?|refresh[_-]?|api[_-]?|auth[_-]?|id[_-]?)?(?:token|code|secret|password|passwd|pwd|key|signature))(["\']?\s*[=:]\s*["\']?)[^\s&;,"\')]+/i';

    private const PATH_CUE = '/^(?:reset|reset-password|password|verify|verify-email|confirm|activate|invite|invitation|accept|unsubscribe|magic|login-link|signed|token|tokens)$/i';

    private const OPAQUE_SEGMENT = '/^(?=.*\d)(?=.*[A-Za-z])[A-Za-z0-9]{20,}$|^(?=.*\d)(?=.*[A-Z])(?=.*[a-z])[A-Za-z0-9_-]{32,}$/';

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
     * A page or request url carries secrets the key-name rules cannot see: an
     * address in ?email=, a one-time ?code=, a reset token as a path segment.
     * The query gets the array rules plus the personal-key rules, and the path
     * loses anything that follows a token cue or looks like an opaque id.
     */
    public function scrubUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $this->scrubMessage($url);
        }

        $rebuilt = isset($parts['host'])
            ? ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : '';

        $rebuilt .= $this->scrubPath($parts['path'] ?? '');

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            $rebuilt .= '?'.http_build_query($this->scrubQuery($query));
        }

        return mb_substr($rebuilt, 0, $this->maxStringLength());
    }

    /**
     * Free text, such as an exception message, where a secret is written as
     * "token=abc", a Bearer header or an address rather than stored under a key.
     */
    public function scrubMessage(string $value): string
    {
        return $this->scrubInline($this->scrubString($value));
    }

    /**
     * Display data, such as page props, where a person's details sit under
     * keys like "name" or "phone_number" that the credential rules ignore.
     *
     * @param  array<array-key, mixed>  $props
     * @return array<string, mixed>
     */
    public function scrubProps(array $props): array
    {
        return $this->scrubPersonal($this->scrubArray($props));
    }

    private function scrubPath(string $path): string
    {
        $segments = explode('/', $path);
        $afterCue = false;

        foreach ($segments as $index => $segment) {
            $plain = rawurldecode($segment);

            if ($plain !== '' && ($afterCue || $this->isOpaque($plain) || preg_match(self::EMAIL, $plain) === 1)) {
                $segments[$index] = self::REDACTED;
            }

            $afterCue = preg_match(self::PATH_CUE, $plain) === 1;
        }

        return implode('/', $segments);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array<string, mixed>
     */
    private function scrubQuery(array $query): array
    {
        $clean = $this->scrubArray($query);

        foreach ($clean as $key => $value) {
            if (preg_match(self::QUERY_KEY, (string) $key) === 1) {
                $clean[$key] = self::REDACTED;
            }
        }

        return $this->scrubPersonal($clean);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scrubPersonal(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match(self::PERSONAL_KEY, (string) $key) === 1) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->scrubPersonal($this->stringKeys($value));
            } elseif (is_string($value)) {
                $values[$key] = $this->isOpaque($value) ? self::REDACTED : $this->scrubInline($value);
            }
        }

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeys(array $value): array
    {
        $keyed = [];

        foreach ($value as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }

    private function scrubInline(string $value): string
    {
        $clean = preg_replace(self::BEARER, 'Bearer '.self::REDACTED, $value) ?? $value;
        $clean = preg_replace(self::ASSIGNED_SECRET, '$1$2'.self::REDACTED, $clean) ?? $clean;

        return preg_replace(self::EMAIL, self::REDACTED, $clean) ?? $clean;
    }

    private function isOpaque(string $segment): bool
    {
        return preg_match(self::OPAQUE_SEGMENT, $segment) === 1;
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
