<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Thijssensoftware\FlareClient\Payload\Sanitiser;
use Thijssensoftware\FlareClient\Support\JobContext;

/**
 * A job whose payload must never be rebuilt.
 *
 * Unserializing the command would run this, inside the error path, in a
 * process that has already failed. Any test that touches the serialised half
 * of a payload uses this to prove it was left alone.
 */
final class ExplodingOnWakeup
{
    public function __wakeup(): void
    {
        throw new RuntimeException('the reporter unserialized the job');
    }
}

function queuedJob(array $payload): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\SendInvoice');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(2);

    return $job;
}

it('says which job it was, by uuid and by name', function (): void {
    $context = JobContext::from(queuedJob([
        'uuid' => '9f1c2e2a-8f1a-4a1e-9a3e-2b7c1d4e5f60',
        'displayName' => 'App\Jobs\SendInvoice',
        'maxTries' => 3,
        'timeout' => 60,
        'data' => ['commandName' => 'App\Jobs\SendInvoice'],
    ]));

    expect($context['job_uuid'])->toBe('9f1c2e2a-8f1a-4a1e-9a3e-2b7c1d4e5f60')
        ->and($context['job_name'])->toBe('App\Jobs\SendInvoice')
        ->and($context['command'])->toBe('App\Jobs\SendInvoice')
        ->and($context['max_tries'])->toBe(3)
        ->and($context['timeout'])->toBe(60);
});

it('never rebuilds the serialised command', function (): void {
    // The whole point: an error tracker that executes the thing it is
    // reporting on is a worse bug than the one it is describing.
    $context = JobContext::from(queuedJob([
        'displayName' => 'App\Jobs\SendInvoice',
        'data' => [
            'commandName' => 'App\Jobs\SendInvoice',
            'command' => serialize(new ExplodingOnWakeup),
        ],
    ]));

    expect($context)->not->toHaveKey('data')
        ->and($context['command'])->toBe('App\Jobs\SendInvoice');
});

it('keeps the plain data a queued listener or mailable carries', function (): void {
    $context = JobContext::from(queuedJob([
        'displayName' => 'App\Listeners\NotifyAccountant',
        'data' => [
            'commandName' => 'App\Listeners\NotifyAccountant',
            'command' => 'irrelevant',
            'invoice_id' => 4821,
            'attempt_window' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
        ],
    ]));

    expect($context['data'])->toBe([
        'invoice_id' => 4821,
        'attempt_window' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
    ]);
});

it('leaves out what it cannot describe', function (): void {
    $context = JobContext::from(queuedJob([
        'uuid' => '',
        'displayName' => null,
        'maxTries' => 'three',
        'data' => 'not an array',
    ]));

    expect($context)->toBe([]);
});

it('says nothing about data that is only objects', function (): void {
    $context = JobContext::from(queuedJob([
        'data' => ['commandName' => 'App\Jobs\X', 'command' => 'x', 'handler' => new stdClass],
    ]));

    expect($context)->not->toHaveKey('data');
});

it('scrubs the job data like any other input', function (): void {
    // A job payload is at least as likely to carry a token as a form post is.
    Http::fake(['*' => Http::response([], 202)]);
    config()->set('flare-client.delivery', 'inline');

    event(new JobFailed('database', queuedJob([
        'displayName' => 'App\Jobs\CallWebhook',
        'data' => [
            'commandName' => 'App\Jobs\CallWebhook',
            'api_token' => 'super-secret-value',
            'invoice_id' => 4821,
        ],
    ]), new RuntimeException('the webhook refused')));

    Http::assertSent(function ($request): bool {
        $origin = $request->data()['origin'];

        return $origin['data']['api_token'] === Sanitiser::REDACTED
            && $origin['data']['invoice_id'] === 4821
            && $origin['job_name'] === 'App\Jobs\CallWebhook'
            && $origin['attempts'] === 2;
    });
});
