<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Doctor\Doctor;
use Thijssensoftware\FlareClient\Doctor\Finding;
use Thijssensoftware\FlareClient\Doctor\Status;
use Thijssensoftware\FlareClient\Transport\Spool;

/**
 * @return array<string, Finding>
 */
function findings(): array
{
    $keyed = [];

    foreach (app(Doctor::class)->run() as $finding) {
        $keyed[$finding->label] = $finding;
    }

    return $keyed;
}

/**
 * Http::fake() merges its stubs and the first match wins, so each test
 * registers the answer it needs rather than inheriting one.
 */
function fakeHealth(int $status = 200): void
{
    Http::fake(['*' => Http::response(['status' => 'ok'], $status)]);
}

/**
 * The provider schedules flare:flush during boot, which is the behaviour under
 * test everywhere except the two cases where an app has no scheduler at all.
 */
function withoutSchedule(): void
{
    app()->instance(Schedule::class, new Schedule);
}

it('passes an install that will actually deliver', function (): void {
    fakeHealth();

    $findings = findings();

    expect(collect($findings)->every(fn (Finding $f): bool => $f->status === Status::Ok))->toBeTrue()
        ->and($findings['spool']->detail)->toBe('empty');
});

it('fails an install with no key, which sends nothing and says nothing', function (): void {
    fakeHealth();
    config()->set('flare-client.key', null);

    expect(findings()['key']->status)->toBe(Status::Fail);
});

it('fails an install with nowhere to report to', function (): void {
    fakeHealth();
    config()->set('flare-client.url', '');

    expect(findings()['url']->status)->toBe(Status::Fail);
});

it('warns when reporting is switched off entirely', function (): void {
    fakeHealth();
    config()->set('flare-client.enabled', false);

    expect(findings()['enabled']->status)->toBe(Status::Warn);
});

it('fails when flare cannot be reached at all', function (): void {
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    $reachable = findings()['reachable'];

    expect($reachable->status)->toBe(Status::Fail)
        ->and($reachable->detail)->toContain('connection refused');
});

it('fails when flare answers with something other than health', function (): void {
    fakeHealth(502);

    expect(findings()['reachable']->detail)->toContain('502');
});

it('warns when the round trip leaves no room inside the timeout', function (): void {
    fakeHealth();

    // The tracker case: it worked, and half of its reports still failed.
    config()->set('flare-client.timeout', 0.0001);

    $reachable = findings()['reachable'];

    expect($reachable->status)->toBe(Status::Warn)
        ->and($reachable->detail)->toContain('too little room');
});

it('fails spool-only delivery with no scheduler, because nothing would ever send', function (): void {
    fakeHealth();
    withoutSchedule();
    config()->set('flare-client.delivery', 'spool');

    $findings = findings();

    expect($findings['delivery']->detail)->toBe('spool only')
        ->and($findings['flush']->status)->toBe(Status::Fail);
});

it('warns inline delivery with no scheduler, because only the retries are lost', function (): void {
    fakeHealth();
    withoutSchedule();

    // billr ran like this for months: inline worked, so nobody noticed that
    // the path which exists for flare being down was not there.
    $findings = findings();

    expect($findings['delivery']->detail)->toBe('inline')
        ->and($findings['flush']->status)->toBe(Status::Warn);
});

it('warns when spooling is off, since an undeliverable event is then a lost one', function (): void {
    fakeHealth();
    config()->set('flare-client.spool.enabled', false);

    expect(findings()['spool']->status)->toBe(Status::Warn);
});

it('counts what is waiting in the spool', function (): void {
    fakeHealth();

    app(Spool::class)->push(['event_id' => 'one']);
    app(Spool::class)->push(['event_id' => 'two']);

    $spool = findings()['spool'];

    expect($spool->status)->toBe(Status::Ok)
        ->and($spool->detail)->toContain('2 event(s)');
});

it('fails when the spool has been sitting there, whatever the schedule claims', function (): void {
    fakeHealth();

    Storage::disk('local')->put('flare-spool/2026-08-01.jsonl', json_encode(['event_id' => 'old'])."\n");
    touch(Storage::disk('local')->path('flare-spool/2026-08-01.jsonl'), time() - 3600);

    $spool = findings()['spool'];

    expect($spool->status)->toBe(Status::Fail)
        ->and($spool->detail)->toContain('the flush is not running');
});

it('treats an unreadable timestamp as no backlog rather than as a failure', function (): void {
    fakeHealth();

    // A spool that lists and reads but cannot be dated. Calling that old would
    // fail an install over a stat that did not work.
    app()->instance(Spool::class, new class extends Spool
    {
        public function files(): array
        {
            return ['flare-spool/2026-08-01.jsonl'];
        }

        public function read(string $path): array
        {
            return [['event_id' => 'one']];
        }

        public function lastModified(string $path): int
        {
            throw new RuntimeException('no timestamp for you');
        }
    });

    $spool = findings()['spool'];

    expect($spool->status)->toBe(Status::Ok)
        ->and($spool->detail)->toContain('1 event(s)');
});

it('reads an app with no scheduler at all as having no flush', function (): void {
    fakeHealth();

    // Not every app binds a Schedule. Asking one that does not has to answer
    // the question rather than become a second failure.
    app()->bind(Schedule::class, fn () => throw new RuntimeException('no scheduler here'));

    expect(findings()['flush']->status)->toBe(Status::Warn);
});

it('exits non-zero when events are being lost', function (): void {
    fakeHealth();
    config()->set('flare-client.key', null);

    $this->artisan('flare:doctor')
        ->expectsOutputToContain('FLARE_KEY is not set')
        ->assertFailed();
});

it('exits zero but says so when something will bite later', function (): void {
    fakeHealth();
    config()->set('flare-client.enabled', false);

    $this->artisan('flare:doctor')
        ->expectsOutputToContain('will bite later')
        ->assertOk();
});

it('says plainly when there is nothing wrong', function (): void {
    fakeHealth();

    $this->artisan('flare:doctor')
        ->expectsOutputToContain('This app can deliver to flare.')
        ->assertOk();
});
