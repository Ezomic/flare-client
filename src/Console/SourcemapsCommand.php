<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Hands a build's source maps to flare, then removes them from the build.
 *
 * Run it right after the frontend build, in the deploy. The maps hold the
 * whole un-minified source of the app, so the removal is the point as much as
 * the upload is: it happens whether the upload worked or not, because a
 * failed deploy that left them in public/ would publish the source anyway.
 * That is also why Vite must build with sourcemap: 'hidden'. Plain true adds
 * a comment to every bundle pointing browsers at the map.
 */
class SourcemapsCommand extends Command
{
    protected $signature = 'flare:sourcemaps
        {--path=public/build : The directory the frontend build wrote to, relative to the app or absolute}
        {--release= : The release the build belongs to, a git sha. Defaults to the release file}
        {--keep : Leave the .map files where they are instead of deleting them}';

    protected $description = 'Upload the build\'s source maps to flare for this release, then delete them';

    public function handle(): int
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            $this->error(sprintf('There is no build directory at %s.', $directory));

            return self::FAILURE;
        }

        $maps = $this->maps($directory);

        if ($maps === []) {
            $this->error(sprintf('No .map files under %s. Build with sourcemap: \'hidden\' in vite.config.', $directory));

            return self::FAILURE;
        }

        $keep = $this->option('keep') === true;

        // Also at shutdown, because a fatal error (a map bigger than the
        // memory limit) skips the finally and would leave the source public.
        if (! $keep) {
            register_shutdown_function(fn () => $this->remove($maps, report: false));
        }

        try {
            $uploaded = $this->upload(array_values(array_filter($maps, fn (SplFileInfo $map): bool => ! $map->isLink())));
        } finally {
            $removed = $keep || $this->remove($maps);
        }

        return $uploaded && $removed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<SplFileInfo>  $maps
     */
    private function upload(array $maps): bool
    {
        if ($maps === []) {
            $this->error('Only symbolic links were found, and those are never followed.');

            return false;
        }

        $release = $this->release();
        $url = rtrim($this->stringConfig('flare-client.url'), '/');
        $key = $this->stringConfig('flare-client.key');

        if ($url === '' || $key === '') {
            $this->error('Set FLARE_URL and FLARE_KEY first.');

            return false;
        }

        if ($release === null) {
            $this->error('No release. Pass --release=<git sha> or write it to the release file.');

            return false;
        }

        if (($duplicate = $this->duplicate($maps)) !== null) {
            $this->error(sprintf('Two maps are called %s. flare stores them by name.', $duplicate));

            return false;
        }

        if (($refused = $this->refused($maps)) !== null) {
            $this->error(sprintf('flare would not accept %s: a map is named like app-B7x2k1.js.map and is not empty. Nothing was sent.', $refused));

            return false;
        }

        $count = 0;

        foreach ($this->batches($maps) as $batch) {
            if (! $this->send($url.'/api/releases/'.$release.'/sourcemaps', $key, $batch)) {
                return false;
            }

            $count += count($batch);
        }

        $this->components->info(sprintf('Uploaded %d source maps for release %s.', $count, substr($release, 0, 7)));

        return true;
    }

    /**
     * @param  list<SplFileInfo>  $batch
     */
    private function send(string $endpoint, string $key, array $batch): bool
    {
        $request = Http::withHeaders(['X-Flare-Key' => $key, 'Accept' => 'application/json'])
            ->connectTimeout(5)
            ->timeout(120);

        foreach ($batch as $map) {
            $request = $request->attach('maps[]', (string) file_get_contents($map->getPathname()), $map->getFilename());
        }

        try {
            $response = $request->post($endpoint);
        } catch (ConnectionException $e) {
            $this->error('Could not reach flare: '.$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            $this->error($this->describe($response));

            return false;
        }

        // PHP drops uploads past max_file_uploads without an error, so the
        // count flare says it stored is the only way to find out.
        if ($response->json('files') !== count($batch)) {
            $this->error(sprintf('flare stored %s of %d maps in that request. Check max_file_uploads on the flare server, or lower flare-client.sourcemaps.batch_files.', (string) json_encode($response->json('files')), count($batch)));

            return false;
        }

        return true;
    }

    private function describe(Response $response): string
    {
        $message = $response->json('message');
        $text = sprintf('flare answered %d%s.', $response->status(), is_string($message) ? ': '.$message : '');

        if ($response->status() === 413) {
            $text .= ' The web server refused the body: raise client_max_body_size (and post_max_size) on flare, or lower flare-client.sourcemaps.batch_bytes.';
        }

        return $text;
    }

    /**
     * Whole files, grouped so no request is bigger than the batch size. A
     * single map over the size still goes, alone, and flare decides.
     *
     * @param  list<SplFileInfo>  $maps
     * @return list<list<SplFileInfo>>
     */
    private function batches(array $maps): array
    {
        $limit = $this->batchBytes();
        $files = $this->batchFiles();
        $batches = [];
        $current = [];
        $size = 0;

        foreach ($maps as $map) {
            if ($current !== [] && ($size + $map->getSize() > $limit || count($current) >= $files)) {
                $batches[] = $current;
                $current = [];
                $size = 0;
            }

            $current[] = $map;
            $size += $map->getSize();
        }

        $batches[] = $current;

        return $batches;
    }

    /**
     * @param  list<SplFileInfo>  $maps
     */
    private function remove(array $maps, bool $report = true): bool
    {
        $failed = 0;

        foreach ($maps as $map) {
            if (! is_link($map->getPathname()) && ! file_exists($map->getPathname())) {
                continue;
            }

            if (! @unlink($map->getPathname())) {
                $failed++;

                if ($report) {
                    $this->error(sprintf('Could not delete %s. Remove it before the build is served.', $map->getPathname()));
                }
            }
        }

        return $failed === 0;
    }

    /**
     * flare takes a whole request or none of it, so a map it would refuse is
     * found here, before the first batch leaves with the others.
     *
     * @param  list<SplFileInfo>  $maps
     */
    private function refused(array $maps): ?string
    {
        foreach ($maps as $map) {
            if ($map->getSize() === 0 || preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,150}\.map$/D', $map->getFilename()) !== 1) {
                return $map->getFilename();
            }
        }

        return null;
    }

    /**
     * @param  list<SplFileInfo>  $maps
     */
    private function duplicate(array $maps): ?string
    {
        $seen = [];

        foreach ($maps as $map) {
            if (isset($seen[$map->getFilename()])) {
                return $map->getFilename();
            }

            $seen[$map->getFilename()] = true;
        }

        return null;
    }

    /**
     * Symlinks are listed so they get deleted, but never uploaded: a link in
     * the build must not make this send a file from somewhere else, and one
     * left behind would still be served.
     *
     * @return list<SplFileInfo>
     */
    private function maps(string $directory): array
    {
        return iterator_to_array((new Finder)->files()->in($directory)->name('*.map'), false);
    }

    private function directory(): string
    {
        $option = $this->option('path');
        $path = is_string($option) && $option !== '' ? $option : 'public/build';

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function release(): ?string
    {
        $option = $this->option('release');

        $raw = is_string($option) && $option !== '' ? $option : $this->releaseFromFile();
        $sha = strtolower(trim($raw ?? ''));

        return preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? $sha : null;
    }

    private function releaseFromFile(): ?string
    {
        $file = $this->stringConfig('flare-client.release_file') ?: 'release.sha';
        $disk = Storage::disk('local');

        return $disk->exists($file) ? (string) $disk->get($file) : null;
    }

    private function batchBytes(): int
    {
        $value = config('flare-client.sourcemaps.batch_bytes', 16 * 1024 * 1024);

        return is_int($value) && $value > 0 ? $value : 16 * 1024 * 1024;
    }

    private function batchFiles(): int
    {
        $value = config('flare-client.sourcemaps.batch_files', 20);

        return is_int($value) && $value > 0 ? $value : 20;
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
