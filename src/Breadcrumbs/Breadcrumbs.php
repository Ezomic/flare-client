<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Breadcrumbs;

use Illuminate\Support\Facades\Date;
use Stringable;
use Thijssensoftware\FlareClient\Payload\Sanitiser;
use Throwable;

/**
 * The last few log records of this process, kept so an event can say what the
 * process was doing before it failed.
 *
 * Everything is scrubbed and bounded when it is recorded, not when it is
 * reported: a record holds no reference to the objects it was logged with, and
 * a secret never sits in memory in the clear longer than the log call itself.
 * The buffer is a hard-capped ring, because this runs on every log line of
 * every app and competes with the payload budget when it is attached.
 */
final class Breadcrumbs
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    private const DEFAULT_BYTES = 16384;

    private const MAX_BYTES = 65536;

    private const MESSAGE_CHARS = 300;

    private const CONTEXT_BYTES = 1024;

    private const CONTEXT_KEYS = 20;

    private const CONTEXT_DEPTH = 3;

    private const CONTEXT_STRING_BYTES = 4096;

    /**
     * Bounds the work spent on a message before it is scrubbed: a megabyte of
     * log line must not cost a megabyte of regex on every call.
     */
    private const MAX_RAW_BYTES = 65536;

    /**
     * Secrets inside free text. The sanitiser's own value patterns match a
     * whole string, which suits a field; a log message is a sentence, and the
     * secret in it is a part.
     */
    private const INLINE_SECRETS = [
        '/\bBearer\s+[\w.~+\/=-]+/i' => 'Bearer [filtered]',
        '/\beyJ[\w-]+\.[\w-]+(?:\.[\w-]*)?/' => '[filtered]',
        '/\b(?:sk|pk|rk)_(?:live|test)_\w+/' => '[filtered]',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*/s' => '[filtered]',
        '/\b[a-f0-9]{32,}\b/i' => '[filtered]',
        '/\b(?:\d[ -]?){13,19}\b/' => '[filtered]',
        '/([\w-]*(?:pass|secret|token|key|auth|signature|session|cookie)[\w-]*["\']?\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;]+)/i' => '$1[filtered]',
    ];

    /**
     * What flare writes about itself. These are never breadcrumbs: a failure
     * to report is not something the app was doing.
     */
    private const OWN_PREFIXES = ['flare-client ', 'flare heartbeats:'];

    /**
     * @var list<array<string, mixed>>
     */
    private array $records = [];

    /**
     * @var list<int>
     */
    private array $sizes = [];

    private int $bytes = 0;

    private bool $busy = false;

    private int $muted = 0;

    public function __construct(private readonly Sanitiser $sanitiser) {}

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function record(string $level, mixed $message, array $context, string $channel): void
    {
        $limit = $this->limit();
        $max = $this->maxBytes();

        if ($this->busy || $this->muted > 0 || $limit < 1 || $max < 1 || ! $this->capturing()) {
            return;
        }

        // Anything that logs while a record is being built, a __toString for
        // one, would otherwise record itself recursively.
        $this->busy = true;

        try {
            $this->push($level, $message, $context, $channel, $limit, $max);
        } catch (Throwable) {
            // A log call is never allowed to fail because of its breadcrumb.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Oldest first, so the event that follows reads as the last line.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * The start of a unit of work. A queue worker is one process for thousands
     * of jobs, and breadcrumbs from job 1 attached to job 200's failure look
     * like evidence, which is worse than having none.
     */
    public function reset(): void
    {
        $this->records = [];
        $this->sizes = [];
        $this->bytes = 0;
    }

    /**
     * Runs work that may log, such as building a payload, without that
     * logging becoming a breadcrumb of the next event.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function muted(callable $callback): mixed
    {
        $this->muted++;

        try {
            return $callback();
        } finally {
            $this->muted--;
        }
    }

    private function capturing(): bool
    {
        return config('flare-client.enabled', true) === true
            && config('flare-client.breadcrumbs.enabled', true) === true
            && is_string(config('flare-client.key'))
            && config('flare-client.key') !== '';
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function push(string $level, mixed $message, array $context, string $channel, int $limit, int $max): void
    {
        $text = $this->message($message);

        foreach (self::OWN_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return;
            }
        }

        $record = array_filter([
            'level' => $this->level($level),
            'message' => $this->text($text, self::MESSAGE_CHARS),
            'channel' => $this->text($channel, 100),
            'timestamp' => Date::now()->format('Y-m-d\TH:i:s.vP'),
            'context' => $this->context($context),
        ], fn (mixed $value): bool => $value !== null);

        $encoded = json_encode($record, JSON_THROW_ON_ERROR);

        // A byte more than the record for the comma that joins it to the next,
        // and one for the closing bracket, so the cap holds for the encoded
        // list and not just for its parts.
        $size = strlen($encoded) + 1;

        if ($size + 1 > $max) {
            return;
        }

        $this->records[] = $record;
        $this->sizes[] = $size;
        $this->bytes += $size;

        $this->evict($limit, $max);
    }

    private function evict(int $limit, int $max): void
    {
        while ($this->records !== [] && (count($this->records) > $limit || $this->bytes + 1 > $max)) {
            array_shift($this->records);
            $this->bytes -= (int) array_shift($this->sizes);
        }
    }

    private function message(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof Stringable) {
            try {
                return (string) $message;
            } catch (Throwable) {
                return '[unprintable]';
            }
        }

        return '['.get_debug_type($message).']';
    }

    /**
     * A level is a word. Anything else is not trusted to be printed.
     */
    private function level(string $level): string
    {
        $level = mb_strtolower(substr($level, 0, 20));

        return preg_match('/^[a-z]+$/', $level) === 1 ? $level : 'unknown';
    }

    private function text(string $value, int $chars): string
    {
        $value = mb_convert_encoding(substr($value, 0, self::MAX_RAW_BYTES), 'UTF-8', 'UTF-8');

        return mb_substr($this->redactInline($this->sanitiser->scrubString($value)), 0, $chars);
    }

    private function redactInline(string $value): string
    {
        return preg_replace(array_keys(self::INLINE_SECRETS), array_values(self::INLINE_SECRETS), $value) ?? '[filtered]';
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function context(array $context): ?array
    {
        if ($context === []) {
            return null;
        }

        $scrubbed = $this->sanitiser->scrubArray($this->clip($context));

        // Encoded here so invalid UTF-8 deep in a value is substituted now,
        // instead of making the whole payload unencodable later.
        $encoded = json_encode($scrubbed, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($encoded === false || strlen($encoded) > self::CONTEXT_BYTES) {
            return ['[truncated]' => true];
        }

        $decoded = json_decode($encoded, true);
        $clean = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $clean[(string) $key] = $value;
        }

        array_walk_recursive($clean, function (mixed &$leaf): void {
            if (is_string($leaf)) {
                $leaf = $this->redactInline($leaf);
            }
        });

        return $clean;
    }

    /**
     * Bounds the work before the sanitiser sees it: a log call that passes a
     * whole collection must not cost a walk over the collection on every line.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function clip(array $value, int $depth = 0): array
    {
        $clipped = [];

        foreach ($value as $key => $item) {
            if (count($clipped) >= self::CONTEXT_KEYS) {
                break;
            }

            if (is_array($item)) {
                $clipped[$key] = $depth + 1 >= self::CONTEXT_DEPTH ? ['[truncated]' => true] : $this->clip($item, $depth + 1);
            } else {
                $clipped[$key] = is_string($item) ? substr($item, 0, self::CONTEXT_STRING_BYTES) : $item;
            }
        }

        return $clipped;
    }

    private function limit(): int
    {
        return $this->intConfig('limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);
    }

    private function maxBytes(): int
    {
        return $this->intConfig('max_bytes', self::DEFAULT_BYTES, self::MAX_BYTES);
    }

    /**
     * A config value that is not an integer falls back to the default, and one
     * out of range is clamped: a typo in the env must not turn a bounded
     * buffer into an unbounded one.
     */
    private function intConfig(string $key, int $default, int $max): int
    {
        $value = config('flare-client.breadcrumbs.'.$key, $default);

        return is_int($value) ? max(0, min($max, $value)) : $default;
    }
}
