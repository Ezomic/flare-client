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

The round trip is only held against the timeout under inline delivery. Under spool the number is
reported and nothing is made of it: the event is a file write, and the round trip is paid later by
the flush, in cron, where a slow one costs nobody anything.

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

It runs in the foreground of `schedule:run`, not in the background. Most apps run the scheduler as
a oneshot systemd unit, and systemd kills everything in the unit once `schedule:run` exits, so a
backgrounded flush died before it could send anything. In the foreground the app's other tasks that
minute wait for it, which is why one run sends at most 10 batches (`--batches`) and leaves the rest
of the spool for the next minute. Heartbeats have a smaller budget of their own (see below), and
`--batches` does not apply to them.

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
* **The payload is built by hand**, not through the usual builder. Measured on the production
  droplet, a normal report costs 2.3 MB to assemble: resolving the reporting graph, scrubbing,
  sizing. After memory exhaustion there is no 2.3 MB, so the handler died inside itself and the
  failure it existed to report was the one it could not. Holding 2.3 MB back per worker for a rare
  event is not a trade worth making either, so the fatal payload is assembled from values already
  in memory instead.
* **A small block of memory is reserved at boot** and released in the handler, which is what buys
  room for that.
* **No source context.** Reading the file around the failing line is an allocation, and there is
  none to make. The file and line are reported; the lines around them are not.
* **An uncaught exception ends the process as a fatal too.** When the handler has already reported
  it, with a real stack, the fatal copy is dropped rather than filed a second time with nothing in
  it.

A fatal has no stack, so the frame flare receives is the file and line PHP recorded. That is what
keeps two exhausted-memory failures in different files apart, which the message alone cannot do.

### Heartbeats

A scheduled task that stops running never throws, so nothing above can see it. Each task that
finishes appends a ping to a spool of its own, and `flare:flush` sends them to flare in one batch,
after the events. flare opens a group in its inbox when a task has been quiet past its grace period.

* **Registration is automatic.** The flush also reports the schedule (task, cron expression,
  timezone) the first time and whenever it changes (a changing description alone does not count). Nothing is configured in two places.
* **A task's name is its artisan command and arguments.** Values that change every run, meaning
  dates, uuids and numbers of five digits or more (a timestamp, an id), are replaced in the name
  only, so `->command('report', [now()->timestamp])` stays one task. flare is still shown the real
  arguments.
* **An unnamed callback is not monitored.** `$schedule->call(fn () => ...)` is just "Callback" and
  cannot be told from the next one. Give it `->name('nightly-sync')` and it is. The same goes for
  `exec` and job tasks, which are monitored too, by their description as the key. Once per process, a
  debug line says an unnamed callback is being skipped.
* **Tasks that decide at run time** (`when`, `skip`, `between`, `unlessBetween`) are pinged but
  registered without an expression and marked `scheduled: false`, so flare knows they exist, never
  alerts on their silence, and stops alerting on one that was only just given a filter.
  A task that is not meant to run in maintenance mode is not told apart from one that stopped.
* **A run that was skipped for overlap is not pinged**: nothing ran, and a success would hide a
  task that is stuck holding its mutex.
* **A background task is pinged when it really finishes**, with its exit code, not when it starts.
* **Heartbeats never touch the error path.** They use their own back-off, kept apart from the
  circuit breaker errors share: 30 minutes after a 404 or 405 (an older flare without the
  route), 5 minutes after a 401, 403, 5xx or a connection error, and `Retry-After` after a 429. A flare that
  does not know the route cannot stop errors being reported. Nothing a heartbeat does can fail a
  scheduled task or `schedule:run`.
* **They are bounded.** One flush sends at most `FLARE_HEARTBEAT_MAX_BATCHES` batches (3, of
  `FLARE_HEARTBEAT_BATCH`, 200) and stops after `FLARE_HEARTBEAT_MAX_SECONDS` (5); the rest waits.
  A file is claimed by renaming it before it is sent, so a ping written meanwhile is never lost,
  and a claim whose sender died is picked up again after `FLARE_HEARTBEAT_STALE_CLAIM` seconds.
* **They share the spool settings.** Heartbeats live in `flare-heartbeats/` beside `flare-spool/`
  on the same disk, with the same per-file and total caps (applied to each directory), and
  `FLARE_SPOOL=false` switches them off too. `FLARE_HEARTBEATS=false` switches off only heartbeats.

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

## Deciding what belongs together

flare groups on the exception class, the normalised message and the top in-app frames. When that
is wrong for an exception the app owns, the exception can name its group itself:

```php
use Thijssensoftware\FlareClient\ProvidesFingerprint;

final class IntegrationException extends RuntimeException implements ProvidesFingerprint
{
    public function __construct(public readonly string $integration, string $message)
    {
        parent::__construct($message);
    }

    public function flareFingerprint(): ?string
    {
        return 'integration:'.$this->integration;
    }
}
```

Every event with the same key lands in one group per project, kind and source, whatever its class,
message or stack. That splits a generic wrapper by integration, and it keeps one bug in one group
when a refactor moves its top frame.

* **Null or blank leaves grouping to flare.** So does a key method that throws: a broken key costs
  the event its custom group, never the event itself.
* **The whole chain is searched, outermost first.** The framework wraps some exceptions itself (a
  `ViewException` around anything thrown in a Blade view), and a wrapper the app did not write must
  not hide the key.
* **The key is scrubbed like any other value**, and flare reads at most 255 characters of it.
* **Changing a key starts a new group.** The old group keeps its history; nothing is re-pointed.
* A flare older than this feature ignores the key and groups as it always did.

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

## Errors from the browser

A second reporter, a script of about 2 KB minified (1.3 KB gzipped; your Vite build minifies the published file), sends the errors a visitor's browser
hits to the same flare. It is a separate door from the server key: a script in a page is readable
by every visitor, so it carries a **public key** that flare only honours from the origins you list.

### Switch it on in flare

```bash
php artisan flare:browser billr --origin=https://billr.thijssensoftware.nl
```

It prints the public key (`flp_...`). Unlike the server key it is not a secret and can be shown
again by running the command without options. `--origin` replaces the list and `--revoke` closes
the door; opening it again mints a new key. Origins are exact:
scheme, host and optional port, no wildcards and no paths.

An origin list stops other *websites* from using the key through a visitor's browser. It cannot stop
a script that sends the header by hand, which is why flare also limits each address and each project,
caps the body, builds every event from a whitelist of fields and never lets this route set what a
server report can.

### Add it to the app

```bash
php artisan vendor:publish --tag=flare-client-browser   # resources/js/vendor/flare.js
```

```ts
// resources/js/app.ts, before createInertiaApp
import { router } from '@inertiajs/vue3';
import flare from './vendor/flare';

const reporter = flare({
    url: import.meta.env.VITE_FLARE_URL + '/api/ingest/browser',
    key: import.meta.env.VITE_FLARE_PUBLIC_KEY,
    release: import.meta.env.VITE_RELEASE,
    router,
});

// inside setup({ el, App, props, plugin }), after createApp(...)
reporter.vue(app);
```

It hooks `window.onerror`, `window.onunhandledrejection`, Vue's `app.config.errorHandler` (the
handler already there is called, or the error is logged as before) and the Inertia router. Inertia 3
names its failure events `networkError` and `httpException`; the older `exception` and `invalid` are
listened to as well. `reporter.report(error)` sends one by hand.

Set `VITE_RELEASE` to the git sha of the build. flare only links an event to a release it already
knows, which the deploy makes known by uploading the source maps (below). For the request id, render
it into the layout:

```blade
<meta name="request-id" content="{{ app(\Thijssensoftware\RequestId\RequestIdContext::class)->current() }}">
```

### What it sends, and what it leaves out

Message, error name, the stack text, the page url, the release, the request id, the Inertia
component, the page props, the viewport. The browser and OS are the `User-Agent` header of the request, stored as sent and
not parsed.

* **Page props are filtered twice.** Keys that look like secrets (`pass`, `token`, `key`, `auth`,
  `session` and so on) are dropped in the browser, and flare runs the same sanitiser over them that
  it runs over PHP request input. Props are often display data full of exactly those fields.
* **Noise is dropped before it is sent**: `ResizeObserver loop` errors, errors whose top frame is
  in a `chrome-extension://`, `moz-extension://` or Safari extension, and the opaque cross-origin
  `Script error.` that carries no stack. flare checks again.
* **The same message and stack is sent once per minute per browser session**, and at most 20 errors
  per page load.
* **Delivery is `fetch` with `keepalive: true`**, so an error during a navigation still lands. The
  body is plain text and there are no custom headers, so the browser never preflights it.
* **Reporting never throws.** Every step of it is wrapped, and a failed request is swallowed.

### Source maps

Vite must build with `sourcemap: 'hidden'`, not `true`. Plain `true` writes a comment into every
bundle that points browsers at the map, and the map holds the whole un-minified source of the app.

```bash
npm run build
php artisan flare:sourcemaps
```

The command uploads every `.map` under `public/build` for the release in `release.sha`
(`--release=` overrides, `--path=` changes the directory) and **deletes them from the build
afterwards, also when the upload fails or the process dies on a fatal error**, so a deploy never
leaves the source public. Symbolic links named `*.map` are deleted but never uploaded. `--keep` is
for debugging. It fails, before sending anything, when there are no maps, a name appears twice, a map is empty or
named in a way flare would refuse (letters, digits, `_`, `.` and `-` only), or the release is not a sha.

flare keeps the maps of the newest releases only (`FLARE_RETENTION_SOURCEMAP_RELEASES`, 5) on a
private disk, and nothing serves them back. Uploads are split into requests of at most
`FLARE_SOURCEMAP_BATCH_BYTES` (16 MB) and `FLARE_SOURCEMAP_BATCH_FILES` (20, PHP's default
`max_file_uploads`); the web server in front of flare has to accept that much, and the command
fails if flare reports storing fewer maps than it was sent.
Reading the maps to show the original source is a later step: until then these events are grouped
by message, file, line and column, with the build's hash stripped from the file name.

## Limits, and what happens at them

| Limit | Default | At the limit |
|-------|---------|--------------|
| `FLARE_TIMEOUT` | 1.5s | The request is abandoned and the event is spooled. This runs inline in the request that threw, so the cost of flare being slow is paid by a user waiting for an error page. |
| `FLARE_CIRCUIT_FAILURES` | 3 | The circuit opens for `FLARE_CIRCUIT_COOLDOWN` (60s) and events go straight to the spool without attempting HTTP. Without this, flare being down during its own deploy would add the full timeout to every request in every app at once. |
| `FLARE_MAX_PAYLOAD_BYTES` | 256 KB | Detail is given up until the payload fits: source context first, then request input, then frames. The payload is flagged `truncated`. Enough frames always survive for flare to group the event correctly. |
| `FLARE_SPOOL_MAX_FILE` | 5 MB | A new spool file is started. |
| `FLARE_SPOOL_MAX_TOTAL` | 20 MB | **The oldest spool file is deleted.** In a storm the newest events describe what is happening now, and a spool that filled the droplet would take down every app on it. |
| `flare:flush --batches` | 10 | The run stops and the rest of the spool goes on the next one. The flush runs in the foreground of `schedule:run`, so this is what keeps a full spool from holding up the app's other scheduled tasks for minutes. |

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
