<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Transport;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Local disk buffer for events that could not be delivered.
 *
 * The size caps are not a tuning knob, they are a safety belt: a spool that
 * grows without limit would fill the droplet and take down every app on it,
 * which would be a spectacular own goal for an error tracker. When the cap is
 * reached the oldest file is dropped, because in a storm the newest events are
 * the ones that describe what is happening now.
 */
class Spool
{
    private const CLAIM = '.sending-';

    public function __construct(
        private readonly string $disk = 'local',
        private readonly ?string $directory = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(array $payload): bool
    {
        if (config('flare-client.spool.enabled', true) !== true) {
            return false;
        }

        $line = json_encode($payload);

        if ($line === false) {
            return false;
        }

        try {
            $this->enforceTotalCap();

            return $this->append($this->currentFile(strlen($line) + 1), $line);
        } catch (Throwable) {
            // A spool that cannot be written must not become an exception in
            // the host app. Losing the event is the lesser failure.
            return false;
        }
    }

    /**
     * Appends one line under an exclusive lock.
     *
     * Storage offers no locking append, and the read-modify-write it forces
     * instead is wrong twice over. It costs a full read and a full write of a
     * file that may be at the 5 MB cap, inline in a request that has already
     * failed. And it is not atomic: during an outage every worker in the app
     * is spooling at once, and two overlapping writes lose one of the two
     * lines, which are precisely the events describing the outage.
     */
    private function append(string $path, string $line): bool
    {
        $storage = Storage::disk($this->disk);

        // Only when missing: creating a directory that is already there resets
        // its permissions, which quietly undoes an operator having locked it.
        if (! $storage->directoryExists($this->directory())) {
            $storage->makeDirectory($this->directory());
        }

        // Suppressed rather than caught: an unwritable spool is a condition to
        // degrade through, not to report, and the caller already treats false
        // as "the event is gone".
        $written = @file_put_contents($storage->path($path), $line."\n", FILE_APPEND | LOCK_EX);

        return $written !== false;
    }

    /**
     * @return array<int, string> spool file paths, oldest first
     */
    public function files(): array
    {
        try {
            $files = Storage::disk($this->disk)->files($this->directory());
        } catch (Throwable) {
            return [];
        }

        $jsonl = array_values(array_filter(
            $files,
            fn (string $file): bool => str_ends_with($file, '.jsonl'),
        ));

        sort($jsonl);

        return $jsonl;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function read(string $path): array
    {
        try {
            $contents = Storage::disk($this->disk)->get($path);
        } catch (Throwable) {
            return [];
        }

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $events = [];

        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * Takes a spool file for sending by renaming it, which is atomic on a local
     * disk: appends made while it is being sent land in a fresh live file and
     * are not lost when this one is deleted. The claim records when it was made
     * so a crashed sender's file can be told apart from one still being sent.
     */
    public function claim(string $path): ?string
    {
        $claimed = $path.self::CLAIM.getmypid().'-'.time();

        try {
            return Storage::disk($this->disk)->move($path, $claimed) ? $claimed : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Claimed files whose sender is long gone.
     *
     * @return array<int, string>
     */
    public function staleClaims(int $seconds): array
    {
        try {
            $files = Storage::disk($this->disk)->files($this->directory());
        } catch (Throwable) {
            return [];
        }

        $stale = [];

        foreach ($files as $file) {
            if (preg_match('/'.preg_quote(self::CLAIM, '/').'\d+-(\d+)$/', $file, $match) === 1 && (int) $match[1] < time() - $seconds) {
                $stale[] = $file;
            }
        }

        sort($stale);

        return $stale;
    }

    public function forget(string $path): void
    {
        try {
            Storage::disk($this->disk)->delete($path);
        } catch (Throwable) {
            // Nothing useful to do: the next flush will try again.
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function rewrite(string $path, array $events): void
    {
        if ($events === []) {
            $this->forget($path);

            return;
        }

        $lines = [];

        foreach ($events as $event) {
            $encoded = json_encode($event);

            if ($encoded !== false) {
                $lines[] = $encoded;
            }
        }

        try {
            Storage::disk($this->disk)->put($path, implode("\n", $lines)."\n");
        } catch (Throwable) {
            // Leave the file as it was rather than risk truncating it.
        }
    }

    /**
     * When the file was last written, which for the oldest file in the spool
     * is how long the flush has been failing to drain it.
     */
    public function lastModified(string $path): int
    {
        return Storage::disk($this->disk)->lastModified($path);
    }

    public function totalBytes(): int
    {
        $total = 0;

        foreach ($this->files() as $file) {
            $total += $this->size($file);
        }

        return $total;
    }

    private function currentFile(int $incoming): string
    {
        $base = $this->directory().'/'.date('Y-m-d');
        $max = $this->intConfig('flare-client.spool.max_file_bytes', 5 * 1024 * 1024);

        $index = 0;

        while (true) {
            $path = $index === 0 ? $base.'.jsonl' : $base.'-'.$index.'.jsonl';

            if ($this->size($path) + $incoming <= $max) {
                return $path;
            }

            $index++;

            if ($index > 100) {
                return $path;
            }
        }
    }

    /**
     * Drops the oldest files until the total is back under the cap.
     *
     * The running total is carried rather than recomputed: measuring it per
     * iteration meant listing the directory and stat-ing every file again for
     * each file dropped, on the hot path of a failing request.
     */
    private function enforceTotalCap(): void
    {
        $max = $this->intConfig('flare-client.spool.max_total_bytes', 20 * 1024 * 1024);

        $files = $this->files();
        $total = $this->totalBytes();

        while ($total > $max && $files !== []) {
            $oldest = array_shift($files);

            $total -= $this->size($oldest);

            $this->forget($oldest);
        }
    }

    private function size(string $path): int
    {
        $storage = Storage::disk($this->disk);

        return $storage->exists($path) ? $storage->size($path) : 0;
    }

    private function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $path = config('flare-client.spool.path', 'flare-spool');

        return is_string($path) && $path !== '' ? $path : 'flare-spool';
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
