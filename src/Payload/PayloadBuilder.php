<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Payload;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\FatalError;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\FlareClient\Support\Runtime;
use Thijssensoftware\RequestId\RequestIdContext;
use Throwable;

class PayloadBuilder
{
    private ?string $release = null;

    private bool $releaseResolved = false;

    public function __construct(
        private readonly Sanitiser $sanitiser,
        private readonly SizeGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $origin
     * @return array<string, mixed>
     */
    public function build(Throwable $e, Source $source, array $origin = [], string $level = 'error'): array
    {
        return $this->guard->fit(array_filter([
            'event_id' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'kind' => 'php',
            'source' => $source->value,
            'level' => $level,
            'environment' => $this->environment(),
            'release_sha' => $this->release(),
            'request_id' => $this->requestId(),
            'sdk' => ['name' => 'flare-client', 'version' => Reporter::VERSION],
            'exception' => $this->exception($e),
            'previous' => $this->previous($e),
            'request' => $this->request($source),
            'user' => $this->user(),
            'context' => $this->context(),
            'origin' => $origin === [] ? null : $this->sanitiser->scrubArray($origin),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function exception(Throwable $e, bool $thrown = true): array
    {
        return [
            'class' => $e::class,
            'message' => $this->sanitiser->scrubString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'frames' => $this->frames($e, $thrown),
        ];
    }

    /**
     * The chain, outermost first. flare fingerprints the last of these.
     *
     * @return array<int, array<string, mixed>>
     */
    private function previous(Throwable $e): array
    {
        $chain = [];
        $current = $e->getPrevious();
        $guard = 0;

        while ($current !== null && $guard < 10) {
            $chain[] = $this->exception($current, thrown: false);
            $current = $current->getPrevious();
            $guard++;
        }

        return $chain;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function frames(Throwable $e, bool $thrown): array
    {
        if ($e instanceof FatalError) {
            return $this->fatalFrame($e);
        }

        $limit = $thrown
            ? $this->intConfig('flare-client.frames.limit', 50)
            : $this->intConfig('flare-client.frames.chain_limit', 15);

        $frames = [];

        foreach (array_slice($e->getTrace(), 0, $limit) as $frame) {
            $file = is_string($frame['file'] ?? null) ? $frame['file'] : '';
            $line = is_int($frame['line'] ?? null) ? $frame['line'] : null;
            $inApp = $this->isInApp($file);

            $frames[] = array_filter([
                'file' => $file,
                'line' => $line,
                'function' => $frame['function'],
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                'type' => is_string($frame['type'] ?? null) ? $frame['type'] : null,
                'in_app' => $inApp,
                // Source context only for our own frames: it is what turns a
                // stack trace into a readable one, and reading vendor files
                // off disk for every frame is a lot of IO for no insight.
                // Only for the exception that was actually thrown. Ten links
                // of a chain each carrying eleven lines per in-app frame is
                // how a payload reaches a size flare refuses outright.
                'context' => $thrown && $inApp && $line !== null ? $this->sourceContext($file, $line) : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $frames;
    }

    /**
     * A fatal has no stack to walk: PHP records where it stopped and nothing
     * else. That location is all there is, and it is also what lets flare tell
     * two exhausted-memory failures in different files apart, which the
     * message alone cannot do.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fatalFrame(FatalError $e): array
    {
        $file = $e->getFile();
        $inApp = $this->isInApp($file);

        return [array_filter([
            'file' => $file,
            'line' => $e->getLine(),
            'in_app' => $inApp,
            'context' => $inApp ? $this->sourceContext($file, $e->getLine()) : null,
        ], fn (mixed $value): bool => $value !== null)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourceContext(string $file, int $line): ?array
    {
        $radius = $this->intConfig('flare-client.frames.context_lines', 5);

        if ($radius < 1) {
            return null;
        }

        // Suppressed and checked rather than guarded with is_file(): a frame
        // can point at something that is not a readable file at all, such as
        // eval'd code, and the read failing says so more directly than three
        // predicates guessing at it.
        $contents = @file($file, FILE_IGNORE_NEW_LINES);

        if ($contents === false) {
            return null;
        }

        $index = $line - 1;
        $start = max(0, $index - $radius);

        return [
            'start' => $start + 1,
            'lines' => array_slice($contents, $start, $radius * 2 + 1),
        ];
    }

    private function isInApp(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        return ! str_contains($file, '/vendor/')
            && ! str_contains($file, '/bootstrap/cache/')
            && ! str_contains($file, '/storage/framework/views/');
    }

    /**
     * The source decides whether a request block means anything.
     *
     * Laravel always has a request bound, even in a queue worker or an artisan
     * command, where it is synthesised from the CLI and describes nothing. The
     * source is the precise discriminator; App::runningInConsole() is not, as
     * it is also true under PHPUnit, which would mean no request block is ever
     * captured in a test and the scrubbing would go unverified.
     *
     * @return array<string, mixed>|null
     */
    private function request(Source $source): ?array
    {
        if ($source !== Source::Http && $source !== Source::Log) {
            return null;
        }

        $request = Runtime::httpRequest();

        if ($request === null) {
            return null;
        }

        return array_filter([
            'method' => $request->getMethod(),
            'url' => $this->sanitiser->scrubUrl($request->fullUrl()),
            'route_name' => $request->route() instanceof Route
                ? $request->route()->getName()
                : null,
            'route_params' => $this->sanitiser->scrubArray($this->routeParams($request)),
            'headers' => $this->sanitiser->scrubHeaders($request->headers->all()),
            'input' => $this->input($request),
            'ip' => $this->sendPii() ? $request->ip() : null,
            'user_agent' => $request->userAgent(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParams(Request $request): array
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return [];
        }

        $params = [];

        foreach ($route->parameters() as $key => $value) {
            $params[(string) $key] = is_object($value) ? '[model]' : $value;
        }

        return $params;
    }

    /**
     * Body capture is narrow on purpose: only where a body is plausible, only
     * for shapes we can scrub, and capped after scrubbing rather than before.
     *
     * @return array<string, mixed>|null
     */
    private function input(Request $request): ?array
    {
        if ($request->isMethod('GET')) {
            return null;
        }

        if (! $request->isJson() && ! $this->isFormRequest($request)) {
            return null;
        }

        $scrubbed = $this->sanitiser->scrubArray($request->all());

        $encoded = json_encode($scrubbed);
        $max = $this->intConfig('flare-client.sanitise.max_body_bytes', 8192);

        if ($encoded !== false && strlen($encoded) > $max) {
            return ['[truncated]' => true, 'bytes' => strlen($encoded)];
        }

        return $scrubbed;
    }

    private function isFormRequest(Request $request): bool
    {
        $type = (string) $request->headers->get('Content-Type', '');

        return str_contains($type, 'form-urlencoded') || str_contains($type, 'form-data');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(): ?array
    {
        if (config('flare-client.sanitise.send_user', true) !== true) {
            return null;
        }

        // No check for whether auth is bound: an app without it throws here,
        // which is the same outcome by a shorter route.
        try {
            $user = auth()->user();
        } catch (Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        return array_filter([
            'id' => $user->getAuthIdentifier(),
            'email' => $this->sendPii() && isset($user->email) && is_string($user->email)
                ? $user->email
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'hostname' => gethostname() ?: null,
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    private function requestId(): ?string
    {
        try {
            return app(RequestIdContext::class)->current();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Written by the deploy script after the checkout. Without it, regression
     * detection and symbolication have nothing to key on.
     *
     * Resolved once per process: the sha cannot change under a running
     * process, and this sits on the path of every reported exception.
     */
    private function release(): ?string
    {
        if ($this->releaseResolved) {
            return $this->release;
        }

        $this->releaseResolved = true;

        $file = config('flare-client.release_file', 'release.sha');

        if (! is_string($file) || $file === '') {
            return null;
        }

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($file)) {
                return null;
            }

            $sha = trim((string) $disk->get($file));
        } catch (Throwable) {
            return null;
        }

        return $this->release = ($sha === '' ? null : mb_substr($sha, 0, 40));
    }

    private function environment(): string
    {
        $env = config('flare-client.environment', 'production');

        return is_string($env) ? $env : 'production';
    }

    private function sendPii(): bool
    {
        return config('flare-client.sanitise.send_pii', false) === true;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }
}
