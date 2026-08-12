# flare-client

Reports exceptions, failed queue jobs and failed scheduled tasks to
[flare](https://flare.thijssensoftware.nl), the self-hosted error collector for the estate.

Its overriding obligation is negative: an error tracker that breaks the app it monitors is worse
than no error tracker. Every path is wrapped, every failure is swallowed to the host app's own log,
and a throwable raised while reporting is never itself reported.

## Install

Neither this package nor `thijssensoftware/request-id` is on Packagist, so the app needs both
repositories. They are public, which is what makes `composer install` work on the droplet without
credentials:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Ezomic/flare-client.git" },
    { "type": "vcs", "url": "https://github.com/Ezomic/request-id.git" }
]
```

```bash
composer require thijssensoftware/flare-client
```

Then set two variables:

```dotenv
FLARE_URL=https://flare.thijssensoftware.nl
FLARE_KEY=flr_...
```

The key is minted on the flare side, per app:

```bash
php artisan flare:project "Billr" --tracker=BILLR
```

It is printed once. flare stores only its hash, so a lost key is rotated (`flare:key:rotate`),
never recovered.

**With no key set, nothing is sent, silently.** That is deliberate: a package that shouted about
its own configuration in every request would be its own kind of outage. It also means a missing key
looks exactly like a quiet app, which is what `flare:test` is for.

## Prove the wiring

```bash
php artisan flare:test
```

It prints where it is reporting to, whether a key is present and whether the client is enabled,
sends one deliberate exception and then tells you what happened to it:

| Outcome | What it means |
|---------|---------------|
| Delivered | flare has the event. Done. |
| Spooled | flare could not be reached. The event is on disk and `flare:flush` will retry it. Check `FLARE_URL` and whether flare is up. |
| Refused | flare said no: rate limited, or the payload was too large. Nothing was spooled. |
| Rate limiting | flare is shedding load from this app. Check the project's `rate_limit_per_minute`. |
| Nothing was sent | Check `FLARE_ENABLED`, `FLARE_KEY` and the console source toggle. |

## Check the install, not just the wiring

```bash
php artisan flare:doctor
```

`flare:test` proves one event can be delivered right now. That is not the same question as "will
this app deliver anything", and the rollout produced two installs that passed the first and failed
the second:

* one had no `schedule:run` entry, so `flare:flush` never ran. Inline delivery still worked, which
  is why nobody noticed the retry path was missing.
* one was configured inline where the round trip to flare takes longer than the timeout it was
  given, so half its reports failed and arrived a minute late through the spool instead.

The doctor checks the key, the url, the enabled switch, how long a real round trip takes **against
the configured timeout**, whether `flare:flush` is scheduled, whether the delivery mode agrees with
that, and whether anything is already sitting in the spool getting old.

It exits non-zero on anything that means events are being lost, so it can be the last step of a
deploy rather than something somebody remembers to run.

## Delivery modes

| `FLARE_DELIVERY` | What happens |
|------------------|--------------|
| `inline` (default) | The event is posted during the request that produced it, falling back to the spool when that fails. |
| `spool` | Nothing is posted inline. The event is written to the spool and `flare:flush` sends it within the minute. |

`spool` was built for flare reporting to itself: an inline post from flare is another ingest
request, and an ingest request that fails would report by making another one, which the per-process
re-entrancy guard cannot see across. Through the spool, a report is a file write and the flush runs
in its own process, where there is nothing to recurse.

It is also the better choice for **any app sharing a host with flare**, which on this estate is all
of them. Measured on the production droplet, an inline report costs 0.7s to 1.3s: TLS handshake to
the public hostname and back through nginx (~0.4s), then flare's own boot and write. Against the
1.5s timeout that is a coin toss, and a lost toss costs the user the full 1.5s on the error page and
still delivers a minute later through the spool. Deferring up front pays nothing inline, batches the
delivery, and arrives just as fast.

Prefer `inline` when flare is genuinely remote and reachable in tens of milliseconds, and when
seeing an error the moment it happens matters more than the latency it costs.

**Do not use `spool` in an app that does not run its scheduler.** Nothing else delivers.

## Delivery depends on the scheduler

An event that cannot be delivered inline is written to a local spool file, and `flare:flush`
replays it. The command is registered automatically and runs every minute.

**An app whose cron does not run `schedule:run` will spool and never drain.** Delivery is built on
the scheduler rather than on a queued job precisely because six apps in the estate have no queue
worker; `Mail::queue` and friends would silently never run there. The scheduler is the one thing
every app has.

## What gets reported

| Source | What it catches | Default |
|--------|-----------------|---------|
| `http` | Exceptions reported through the framework exception handler during a request | on |
| `job` | Failed queue jobs, with the job class, queue, connection, attempt count and what the job was working on | on |
| `schedule` | Failed scheduled tasks, and background tasks that exit non-zero | on |
| `console` | Commands that exit non-zero | on |
| `log` | `Log::error()` and above with no exception behind them | off |

Fatal errors are captured separately, because they are not a source: they are what happens when
there is no exception to have a source. See below.

Each can be switched off with `FLARE_SOURCE_HTTP=false` and so on.

The schedule source is the one worth having on everywhere: cron pipes `schedule:run` to
`/dev/null` on most lines, so a task that has been failing for three weeks looks exactly like one
that is working.

### The log source

Off by default because it can flood: one misbehaving loop writing `Log::error()` fills both the
spool and flare's retention. When you switch it on with `FLARE_SOURCE_LOG=true`, `FLARE_LOG_LEVEL`
is the floor (`error` by default, PSR-3 names).

Records that already carry an exception in their context are left alone: the handler reports those,
and reporting them twice would hide the real stack behind the log call's.

flare has its own switch per project on top of this one, so log capture can be refused centrally
without redeploying the app.

### Fatal errors

The exception handler sees everything that is thrown. It never sees the process being killed:
memory exhausted, `max_execution_time` reached, a file that would not compile. PHP writes the error
and stops, and there is nothing to catch.

Those are captured from a shutdown function and switched off with `FLARE_CAPTURE_FATALS=false`.
Three things are worth knowing:

* **They always go through the spool**, whatever `FLARE_DELIVERY` says. A process that died because
  it ran out of memory cannot open a socket; it can still append a line to a file.
* **A small block of memory is reserved at boot** and released in the handler, so a report about
  exhausted memory has room to be built.
* **An uncaught exception ends the process as a fatal too.** When the handler has already reported
  it, with a real stack, the fatal copy is dropped rather than filed a second time with nothing in
  it.

A fatal has no stack, so the frame flare receives is the file and line PHP recorded. That is what
keeps two exhausted-memory failures in different files apart, which the message alone cannot do.

### Apps with a custom exception handler

Reporting hooks the framework handler's `reportable()` callback. **An app that has swapped
`ExceptionHandler` for a class of its own gets nothing automatically** and has to call the reporter
itself:

```php
app(\Thijssensoftware\FlareClient\Reporter::class)->report($e);
```

This is documented rather than worked around, because guessing at a custom handler's shape is worse
than being clear about it.

### Flow control is never reported

`ValidationException`, `NotFoundHttpException`, `AuthenticationException` and the rest of the
flow-control set are filtered before anything is sent. An app adds to that list; it cannot shorten
it by accident, because the defaults are merged in rather than replaced:

```php
'extra_ignore_exceptions' => [
    \App\Exceptions\PaymentDeclined::class,
],
```

## What is scrubbed

Three redundant layers, because the cost of a miss is a live credential sitting in a database
forever:

1. **Header names.** `Authorization`, `Cookie`, `X-Api-Key` and friends.
2. **Key names at any depth.** Anything matching `pass|secret|token|key|auth|credit|card|cvv|iban|bsn|ssn|otp|pin|signature|session`, in request input, route parameters, query strings and log context alike. Add your own with `sanitise.extra_keys`.
3. **Value shapes.** Bearer tokens, JWTs, private keys, long hex strings, card-shaped numbers and provider keys, whatever the field happens to be called.

Query strings get the same treatment as bodies, which is easy to forget: a password reset link puts
the token right there in `?token=`.

`FLARE_SEND_PII` defaults to **false**, so no IP addresses and no email addresses leave the app.
`FLARE_SEND_USER` defaults to true and sends only the user id.

Publish the config to change any of it:

```bash
php artisan vendor:publish --tag=flare-client-config
```

## Limits, and what happens at them

| Limit | Default | At the limit |
|-------|---------|--------------|
| `FLARE_TIMEOUT` | 1.5s | The request is abandoned and the event is spooled. This runs inline in the request that threw, so the cost of flare being slow is paid by a user waiting for an error page. |
| `FLARE_CIRCUIT_FAILURES` | 3 | The circuit opens for `FLARE_CIRCUIT_COOLDOWN` (60s) and events go straight to the spool without attempting HTTP. Without this, flare being down during its own deploy would add the full timeout to every request in every app at once. |
| `FLARE_MAX_PAYLOAD_BYTES` | 256 KB | Detail is given up until the payload fits: source context first, then request input, then frames. The payload is flagged `truncated`. Enough frames always survive for flare to group the event correctly. |
| `FLARE_SPOOL_MAX_FILE` | 5 MB | A new spool file is started. |
| `FLARE_SPOOL_MAX_TOTAL` | 20 MB | **The oldest spool file is deleted.** In a storm the newest events describe what is happening now, and a spool that filled the droplet would take down every app on it. |

## Correlation with snag

Every payload carries the `X-Request-Id` stamped by
[`thijssensoftware/request-id`](https://github.com/Ezomic/request-id), which snag stamps too. A
human bug report can be traced to the exception behind it after the fact.

## Local development

```bash
composer ci:check      # Pint, PHPStan level 10, Pest
composer test:coverage # the 100% gate CI enforces
```

The package runs inside every app in the estate, which is what the quality bar is for. It is not
lowered.
