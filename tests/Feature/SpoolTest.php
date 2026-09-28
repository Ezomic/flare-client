<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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

it('schedules the flush in the foreground, where a oneshot scheduler cannot kill it', function (): void {
    // Most apps run schedule:run as a oneshot systemd unit, and systemd kills
    // the unit's whole control group once schedule:run exits. A flush sent to
    // the background went with it, so the spool never drained.
    $flush = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'flare:flush'));

    expect($flush)->toBeInstanceOf(Event::class)
        ->and($flush->runInBackground)->toBeFalse()
        ->and($flush->withoutOverlapping)->toBeTrue()
        ->and($flush->expiresAt)->toBe(5)
        ->and($flush->expression)->toBe('* * * * *');
});

it('sends at most ten batches in one run and leaves the rest for the next', function (): void {
    // In the foreground, every other task the app has that minute waits for
    // the flush. A full spool after an outage is hundreds of batches.
    config()->set('flare-client.spool.batch_size', 1);

    $spool = app(Spool::class);

    foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $day => $name) {
        $lines = array_map(
            fn (int $i): string => (string) json_encode(['event_id' => 'event-'.($day * 4 + $i)]),
            range(1, 4),
        );

        Storage::disk('local')->put('flare-spool/'.$name.'.jsonl', implode("\n", $lines)."\n");
    }

    $posted = [];

    Http::fake(function ($request) use (&$posted) {
        $posted[] = $request->data()['events'];

        return Http::response(['accepted' => 1], 202);
    });

    $this->artisan('flare:flush')->assertOk();

    expect($posted)->toHaveCount(10)
        ->and($spool->files())->toBe(['flare-spool/2026-08-03.jsonl'])
        ->and($spool->read('flare-spool/2026-08-03.jsonl'))->toBe([
            ['event_id' => 'event-11'],
            ['event_id' => 'event-12'],
        ]);

    $this->artisan('flare:flush')->assertOk();

    expect(array_slice($posted, 10))->toBe([[['event_id' => 'event-11']], [['event_id' => 'event-12']]])
        ->and($spool->files())->toBeEmpty();
});

it('leaves a file the run has no batches left for exactly as it was', function (): void {
    // Rewriting it anyway would reset its age, and the age of the oldest file
    // is how the doctor tells a flush that is not running.
    config()->set('flare-client.spool.batch_size', 1);

    $spool = app(Spool::class);

    Storage::disk('local')->put('flare-spool/2026-08-01.jsonl', json_encode(['event_id' => 'one'])."\n".json_encode(['event_id' => 'two'])."\n");
    Storage::disk('local')->put('flare-spool/2026-08-02.jsonl', json_encode(['event_id' => 'three'])."\n");

    $untouched = time() - 3600;
    touch(Storage::disk('local')->path('flare-spool/2026-08-02.jsonl'), $untouched);

    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('flare:flush', ['--batches' => 2])->assertOk();

    Http::assertSentCount(2);

    expect($spool->files())->toBe(['flare-spool/2026-08-02.jsonl'])
        ->and($spool->lastModified('flare-spool/2026-08-02.jsonl'))->toBe($untouched);
});

it('keeps the events past the batch cap when flare stops part way', function (): void {
    config()->set('flare-client.spool.batch_size', 2);

    $spool = app(Spool::class);

    foreach (range(1, 5) as $i) {
        $spool->push(['event_id' => 'event-'.$i]);
    }

    Http::fake(['*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('flare:flush', ['--batches' => 2])
        ->expectsOutputToContain('4 left spooled')
        ->assertOk();

    Http::assertSentCount(1);

    expect($spool->read($spool->files()[0]))->toBe([
        ['event_id' => 'event-2'],
        ['event_id' => 'event-3'],
        ['event_id' => 'event-4'],
        ['event_id' => 'event-5'],
    ]);
});
