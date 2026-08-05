<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Transport\CircuitBreaker;
use Thijssensoftware\FlareClient\Transport\Delivery;
use Thijssensoftware\FlareClient\Transport\Spool;
use Thijssensoftware\FlareClient\Transport\Transport;

it('round-trips events through the spool', function (): void {
    $spool = app(Spool::class);

    $spool->push(['event_id' => 'one']);
    $spool->push(['event_id' => 'two']);

    $files = $spool->files();

    expect($files)->toHaveCount(1)
        ->and($spool->read($files[0]))->toBe([['event_id' => 'one'], ['event_id' => 'two']]);
});

it('rolls over to a new file at the per-file cap', function (): void {
    config()->set('flare-client.spool.max_file_bytes', 200);

    $spool = app(Spool::class);

    foreach (range(1, 6) as $i) {
        $spool->push(['event_id' => 'event-'.$i, 'padding' => str_repeat('x', 60)]);
    }

    expect(count($spool->files()))->toBeGreaterThan(1);
});

it('drops the oldest file rather than filling the disk', function (): void {
    // The cap is a safety belt, not a tuning knob: a spool that grows without
    // limit would fill the droplet and take down every app on it.
    config()->set('flare-client.spool.max_file_bytes', 200);
    config()->set('flare-client.spool.max_total_bytes', 600);

    $spool = app(Spool::class);

    foreach (range(1, 40) as $i) {
        $spool->push(['event_id' => 'event-'.$i, 'padding' => str_repeat('x', 60)]);
    }

    expect($spool->totalBytes())->toBeLessThanOrEqual(1200)
        ->and($spool->files())->not->toBeEmpty();
});

it('writes nothing when spooling is switched off', function (): void {
    config()->set('flare-client.spool.enabled', false);

    expect(app(Spool::class)->push(['event_id' => 'one']))->toBeFalse()
        ->and(app(Spool::class)->files())->toBeEmpty();
});

it('skips junk lines when reading', function (): void {
    Storage::disk('local')->put('flare-spool/2026-08-01.jsonl', "{\"event_id\":\"ok\"}\nnot json\n\n");

    expect(app(Spool::class)->read('flare-spool/2026-08-01.jsonl'))->toBe([['event_id' => 'ok']]);
});

it('returns nothing for a file that is not there', function (): void {
    expect(app(Spool::class)->read('flare-spool/missing.jsonl'))->toBe([]);
});

it('deletes the file when rewritten with nothing left', function (): void {
    $spool = app(Spool::class);
    $spool->push(['event_id' => 'one']);

    $file = $spool->files()[0];
    $spool->rewrite($file, []);

    expect($spool->files())->toBeEmpty();
});

it('flushes spooled events and clears them', function (): void {
    $spool = app(Spool::class);
    $spool->push(['event_id' => 'one']);
    $spool->push(['event_id' => 'two']);

    Http::fake(['*' => Http::response(['accepted' => 2], 202)]);

    $this->artisan('flare:flush')->assertOk();

    expect($spool->files())->toBeEmpty();
});

it('leaves the spool alone when flare is still down', function (): void {
    $spool = app(Spool::class);
    $spool->push(['event_id' => 'one']);

    Http::fake(['*' => Http::response(['message' => 'nope'], 503)]);

    $this->artisan('flare:flush')->assertOk();

    expect($spool->files())->toHaveCount(1)
        ->and($spool->read($spool->files()[0]))->toHaveCount(1);
});

it('reports an empty spool without making a request', function (): void {
    Http::fake();

    $this->artisan('flare:flush')->expectsOutputToContain('Nothing spooled.')->assertOk();

    Http::assertNothingSent();
});

it('sends a batch in chunks of the configured size', function (): void {
    config()->set('flare-client.spool.batch_size', 2);

    $spool = app(Spool::class);

    foreach (range(1, 5) as $i) {
        $spool->push(['event_id' => 'event-'.$i]);
    }

    $batches = 0;

    Http::fake(function () use (&$batches) {
        $batches++;

        return Http::response(['accepted' => 2], 202);
    });

    $this->artisan('flare:flush')->assertOk();

    expect($batches)->toBe(3)
        ->and($spool->files())->toBeEmpty();
});

it('does not attempt a batch while the circuit is open', function (): void {
    config()->set('flare-client.circuit.failures', 1);

    app(CircuitBreaker::class)->recordFailure();

    Http::fake();

    expect(app(Transport::class)->sendBatch([['event_id' => 'one']])->delivery)->toBe(Delivery::Spooled);

    Http::assertNothingSent();
});

it('treats an empty batch as already delivered', function (): void {
    Http::fake();

    expect(app(Transport::class)->sendBatch([])->delivery)->toBe(Delivery::Sent);

    Http::assertNothingSent();
});

it('mutes on a batch 429 rather than hammering flare', function (): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '90'])]);

    expect(app(Transport::class)->sendBatch([['event_id' => 'one']])->delivery)->toBe(Delivery::Throttled)
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});

it('appends concurrently without losing lines', function (): void {
    // The failure this replaces: read the file, add a line, write it back.
    // Two workers doing that at once keep only one of the two events, and
    // during an outage every worker in the app is spooling at once.
    $spool = app(Spool::class);
    $path = Storage::disk('local')->path('flare-spool');

    @mkdir($path, 0755, true);

    $children = [];

    foreach (range(1, 8) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            $spool->push(['event_id' => 'event-'.$i, 'padding' => str_repeat('x', 200)]);

            exit(0);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $lines = 0;

    foreach ($spool->files() as $file) {
        $lines += count($spool->read($file));
    }

    expect($lines)->toBe(8);
})->skip(! function_exists('pcntl_fork'), 'pcntl is not available');

it('reports a failed write rather than throwing', function (): void {
    $spool = app(Spool::class);
    $directory = Storage::disk('local')->path('flare-spool');

    @mkdir($directory, 0755, true);
    chmod($directory, 0555);

    $result = $spool->push(['event_id' => 'one']);

    chmod($directory, 0755);

    expect($result)->toBeFalse();
})->skip(posix_getuid() === 0, 'root ignores the permission bits');

it('keeps the events a partial batch did not take', function (): void {
    $spool = app(Spool::class);

    foreach (range(1, 5) as $i) {
        $spool->push(['event_id' => 'event-'.$i]);
    }

    // flare stops at the first event past the project ceiling and reports the
    // count. Reading a 202 as full delivery threw the other four away.
    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('flare:flush')->assertOk();

    $files = $spool->files();

    expect($files)->toHaveCount(1)
        ->and($spool->read($files[0]))->toBe([
            ['event_id' => 'event-2'],
            ['event_id' => 'event-3'],
            ['event_id' => 'event-4'],
            ['event_id' => 'event-5'],
        ]);
});

it('sends exactly what was left over on the next run', function (): void {
    $spool = app(Spool::class);

    foreach (range(1, 3) as $i) {
        $spool->push(['event_id' => 'event-'.$i]);
    }

    $accepted = 1;
    $posted = [];

    Http::fake(function ($request) use (&$posted, &$accepted) {
        $posted[] = $request->data()['events'];

        return Http::response(['accepted' => $accepted], 202);
    });

    $this->artisan('flare:flush')->assertOk();

    $accepted = 2;

    $this->artisan('flare:flush')->assertOk();

    expect($posted[1])->toBe([['event_id' => 'event-2'], ['event_id' => 'event-3']])
        ->and($spool->files())->toBeEmpty();
});

it('stops walking the spool once flare starts shedding', function (): void {
    $spool = app(Spool::class);

    Storage::disk('local')->put('flare-spool/2026-08-01.jsonl', json_encode(['event_id' => 'old'])."\n");
    Storage::disk('local')->put('flare-spool/2026-08-02.jsonl', json_encode(['event_id' => 'newer'])."\n");

    $requests = 0;

    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response(['accepted' => 0], 202);
    });

    $this->artisan('flare:flush')->assertOk();

    expect($requests)->toBe(1)
        ->and($spool->files())->toHaveCount(2);
});

it('treats a 202 with no count as the whole batch', function (): void {
    $spool = app(Spool::class);
    $spool->push(['event_id' => 'one']);

    // Inventing a smaller number would mean replaying events flare has
    // already recorded.
    Http::fake(['*' => Http::response([], 202)]);

    $this->artisan('flare:flush')->assertOk();

    expect($spool->files())->toBeEmpty();
});

it('never counts more than it sent even if flare says otherwise', function (): void {
    Http::fake(['*' => Http::response(['accepted' => 99], 202)]);

    expect(app(Transport::class)->sendBatch([['event_id' => 'one']])->accepted)->toBe(1);
});
