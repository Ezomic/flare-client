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
    public function __construct(private readonly string $disk = 'local') {}

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

            $path = $this->currentFile(strlen($line) + 1);

            $storage = Storage::disk($this->disk);

            $existing = $storage->exists($path) ? $storage->get($path) : '';

            $storage->put($path, $existing.$line."\n");

            return true;
        } catch (Throwable) {
            // A spool that cannot be written must not become an exception in
            // the host app. Losing the event is the lesser failure.
            return false;
        }
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

    public function totalBytes(): int
    {
        $total = 0;

        foreach ($this->files() as $file) {
            try {
                $total += Storage::disk($this->disk)->size($file);
            } catch (Throwable) {
                continue;
            }
        }

        return $total;
    }

    private function currentFile(int $incoming): string
    {
        $storage = Storage::disk($this->disk);
        $base = $this->directory().'/'.date('Y-m-d');
        $max = $this->intConfig('flare-client.spool.max_file_bytes', 5 * 1024 * 1024);

        $index = 0;

        while (true) {
            $path = $index === 0 ? $base.'.jsonl' : $base.'-'.$index.'.jsonl';

            $size = $storage->exists($path) ? $storage->size($path) : 0;

            if ($size + $incoming <= $max) {
                return $path;
            }

            $index++;

            if ($index > 100) {
                return $path;
            }
        }
    }

    private function enforceTotalCap(): void
    {
        $max = $this->intConfig('flare-client.spool.max_total_bytes', 20 * 1024 * 1024);

        $files = $this->files();

        while ($this->totalBytes() > $max && $files !== []) {
            $this->forget(array_shift($files));
        }
    }

    private function directory(): string
    {
        $path = config('flare-client.spool.path', 'flare-spool');

        return is_string($path) && $path !== '' ? $path : 'flare-spool';
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
