# Async — Go-style green threads on Manticore

Part of the standard distribution: the runtime ships in `prelude/async.php` (demand-gated on
an `Async` mention), the demos live in `examples/async/`. A **pure-PHP** structured-concurrency
runtime over Manticore's two async primitives —
**Fibers** (stackful, cheap) and **`Io\Poll`** (kqueue/epoll readiness). No new compiler
intrinsics: the scheduler, reactor, tasks, channels and I/O are ordinary PHP compiled to
native.

It ships in **`prelude/async.php`** and is demand-gated: a program that never names
`Async\` carries none of it (naming it also forces `\Fiber` and `Io\Poll` on). Nothing to
install, nothing to link — `use function Async\spawn;` is enough.

This directory holds the examples and benchmarks.

## Model (not Promises)

The concurrency *model* is Go's — cheap tasks, channels, a netpoller, blocking-*looking* I/O
that transparently suspends the current fiber and resumes it when the fd is ready. The
*spelling* is PHP's: objects instead of multi-return tuples, `foreach` instead of a comma-ok
loop, typed exceptions instead of sentinels, plain streams instead of a hand-rolled fd layer.

```php
use function Async\async;
use function Async\spawn;
use Async\TaskGroup;

async(function () {
    TaskGroup::run(function (TaskGroup $g) {
        $g->spawn(fn() => work("a"));
        $g->spawn(fn() => work("b"));
    }); // returns only after both children finish; a throw propagates
});
```

## Guarantees

- **Every task is owned by a scope.** `async()` opens an implicit root scope, so even a
  top-level `spawn()` is joined — there is no fire-and-forget.
- **The scope lives on the task**, not in a global stack: nested `TaskGroup::run()` calls in
  concurrently-running tasks never adopt each other's children.
- **Cancellation is real, and sticky.** The first failure in a scope cancels its siblings by
  deregistering them from the reactor, the timer heap and every channel queue, then resuming
  them with an `Async\CancelledException` *at their suspend point* — not by setting a flag and
  hoping. It is re-raised at **every** subsequent suspend point, so a blanket
  `catch (\Throwable)` buys a task one more suspend, not immunity; and
  `CancelledException extends \Error`, so the far more common `catch (\Exception)` cannot see
  it at all. `Context::throwIfCancelled()` is the extra checkpoint for a CPU-bound loop that
  never suspends; `Async\shield()` is the escape hatch for cleanup that must itself suspend.
- **No failure is silently dropped.** A task whose outcome nobody claimed escalates to its
  owning scope. Conversely, a failure you *did* catch stays caught — it is not re-thrown at
  program exit.
- **A deadlock is reported.** If the loop runs out of work while tasks are still parked,
  `async()` throws `Async\DeadlockException` instead of exiting `rc=0` (Go's "all goroutines
  are asleep").
- `awaitAll` is **fail-fast**: results keyed by input position, and the first failure
  cancels + joins the rest and is rethrown as-is. `awaitAny` returns the first success and
  throws an `AggregateError` (keyed by input position) only if everything failed.

## API

| symbol | role |
|---|---|
| `Async\async(callable): mixed` | run a program on the engine and return its value |
| `Async\group(callable): mixed` | open a child scope, join it, return the body's value |
| `Async\spawn(callable, ...$args): Task` | start a task in the calling task's scope |
| `Async\timeout(float, callable): mixed` | run a scope under a deadline; `TimeoutException` on expiry |
| `Async\shield(callable): mixed` | hold cancellation back for cleanup that must suspend |
| `Async\awaitAll(Task ...): array` | all results by input position; fail-fast |
| `Async\awaitAny(Task ...): mixed` | first success, else `AggregateError` |
| `Async\mapConcurrent(array, callable, int): array` | map with at most N in flight; fail-fast |
| `Async\delay(float $seconds)` | suspend without blocking the loop |
| `Async\channel(int $cap = 0): Channel` | CSP channel; 0 = unbuffered rendezvous |
| `Async\select(array $cases): Selected` | wait for the first ready case (recv or send) |
| `Async\selectNow(array $cases): ?Selected` | …non-blocking (Go's `default:`) |
| `Async\selectWithin(float, array): ?Selected` | …with a deadline (Go's `time.After`) |
| `Async\shutdownOn(int ...$signals)` | graceful shutdown — cancel the root scope |
| `pcntl_signal(int, callable)` | ordinary php; inside `async()` it runs as a task |
| `Process\supervise(int $n, callable)` | fork N workers, restart the dead, forward SIGTERM |
| `Process\workers(int $n): int` | fork N shared-nothing workers, unsupervised |
| `Process\fork/pid/ppid` | the process model — deliberately NOT in `Async\` |
| `Mutex` — `lock/tryLock/unlock/withLock` | a critical section that SUSPENDS in the middle |
| `Once::run(callable)` | build a lazy thing exactly once under contention |
| `Async\awaitAllSettled` / `mapSettled` | collect outcomes instead of failing fast |
| `Task::join(): Settled` | wait without rethrowing (what you want after `cancel()`) |
| `Task::await(): mixed` | wait for one task |
| `Task::awaitWithin(float): mixed` | …with a deadline; cancels the task on expiry |
| `Channel` (`IteratorAggregate`) | `foreach ($ch as $v)` — ends when closed and drained |
| `Channel::send/next/recv/close` | `next(): Received` is the comma-ok form |
| `SelectCase::recv(Channel)` / `::send(Channel, $v)` | one arm of a `select()` |
| `Semaphore(int)` — `acquire/release/withPermit` | "N at a time" |
| `TaskGroup::run(callable)` | what `group()` wraps |
| `TaskGroup::token(): CancellationToken` | the read-only half of this scope's cancellation |
| `TaskGroup::cancel()` | the write half |
| `Context::token()/isCancelled()/throwIfCancelled()` | the calling task's scope, ambiently |
| `Context::deadline()/remaining()` | the effective deadline, ambiently |
| `Context::value(string)` / `::withValue(string, $v, callable)` | scoped values (request-id) |

### Scopes, deadlines, cancellation

`async()` and `group()` both return the body's value, so a concurrent block reads like an
ordinary call:

```php
$rows = Async\async(fn() => Async\group(function (TaskGroup $g) {
    $a = $g->spawn(fn() => fetch(1));
    $b = $g->spawn(fn() => fetch(2));
    return [$a->await(), $b->await()];
}));
```

`timeout()` is a scope with a deadline. On expiry the body **and everything it spawned** is
cancelled and joined before `TimeoutException` is thrown — a timeout that leaves work running
is not a timeout. Nesting only ever tightens: a 30 s inner scope inside a 2 s outer one still
dies at 2 s.

```php
$page = Async\timeout(2.0, fn() => file_get_contents($url));
$row  = $task->awaitWithin(0.5);          // per-task deadline
Async\Context::remaining();               // seconds left, ambiently; null = unbounded
```

Cancellation has two halves of one object rather than a second mechanism to keep in sync with
the scope tree: the **scope is the source** (`$g->cancel()`), and a **`CancellationToken` is
its read-only view** (`$g->token()`, or `Context::token()` from inside a task). Pass the token
into a helper to let it observe cancellation without granting it the power to cancel.

```php
Async\group(function (TaskGroup $g) {
    $tok = $g->token();
    $tok->onCancel(fn() => $handle->release());   // fires once, inside cancel()
    $g->spawn(function () use ($tok) {
        while (!$tok->isCancelled()) { step(); }  // CPU-bound: no suspend point to throw at
    });
});
```

A task that *does* suspend needs none of that — cancellation is delivered as a
`CancelledException` at its suspend point, and again at the next one, and the one after that.
Cleanup that must itself suspend goes in a `shield()`, which is the only thing that holds it
back:

```php
try {
    Async\delay(30.0);
} catch (Async\CancelledException $e) {
    Async\shield(fn() => $conn->sendCloseFrame());   // needs I/O; keep it short
    throw $e;
}
```

### Channels

Consumption is a `foreach` — the loop ends when the channel is closed and drained:

```php
$ch = Async\channel();
Async\spawn(function () use ($ch) { $ch->send(1); $ch->send(2); $ch->close(); });
foreach ($ch as $value) { … }
```

`next(): Received` is the explicit form when `null` is a legal payload (`->value`, `->ok`);
`recv(): mixed` is the terse one that cannot tell a null payload from a closed channel.
Sending to a closed channel throws `Async\ChannelClosedException`.

`select()` takes `SelectCase`s — a bare `Channel` is shorthand for a receive — and returns a
`Selected` (`->index`, `->value`, `->ok`, `->channel`, `->isSend`), because PHP has no
multi-return and a positional array is not worth pretending otherwise:

```php
$r = Async\select([$a, Async\SelectCase::send($b, $v)]);
if ($r->isSend) { … } else { echo $r->value; }

Async\selectNow([$a, $b]);            // null when nothing is ready
Async\selectWithin(0.5, [$a, $b]);    // null on expiry
```

Exactly one case fires: a waiter parked across several channels is won by the first channel to
reach it, and the losers see the claim and skip it.

### Signals and graceful shutdown

There is a real `pcntl` layer under this — `pcntl_signal`, `pcntl_signal_dispatch`,
`pcntl_sigprocmask`, `pcntl_fork`, `pcntl_waitpid`, `posix_kill`, the `SIG*` constants — and
it works with or without the scheduler, matching the interpreter (`pcntl_signal.php` is
difftested against it). A C handler cannot be a PHP closure, so a handled signal is *blocked*
and collected at a dispatch point instead. That is php's own deferred model; the difference
is only that a blocked signal never interrupts a syscall, which for a cooperative loop is a
feature.

Inside `async()` the dispatch runs in a **daemon task** in the root scope, so a handler is
ordinary async code — it can allocate, throw, `spawn()` and suspend. Being a daemon, it does
not keep the program alive once the real work is done. There is no async-specific way to
register one: the scheduler notices a non-empty registry and starts pumping, so plain
`pcntl_signal()` is all you write.

Process control — `Process\fork`, `Process\workers`, `Process\supervise` — lives beside
pcntl rather than in `Async\`, because none of it runs a scheduler. The process model sits
under concurrency, not inside it.

```php
Async\async(function () {
    Async\shutdownOn(SIGTERM, SIGINT);
    serveForever();                       // unwinds cleanly on the signal
});
```

`shutdownOn()` cancels the **root scope**, and everything else falls out of cancellation
already being structured: the accept loop and every live connection raise
`CancelledException` at their next suspend point, each scope joins its children, `shield()`
covers a last write, and `async()` returns normally — a root cancellation is a shutdown, not
a failure. `examples/async/server.php` is the whole shape: `supervise(4, …)` forks four workers,
restarts one that crashes, and forwards `SIGTERM` to the group.

### Bounded concurrency

```php
$pages = Async\mapConcurrent($urls, fn($u) => file_get_contents($u), 10);   // 10 at a time
$sem = new Async\Semaphore(4);
$sem->withPermit(fn() => work());
```

### Scoped values

```php
Async\Context::withValue('request-id', $id, function () {
    Async\spawn(fn() => log(Async\Context::value('request-id')));   // visible in every child
});
```

## Transparent I/O

Ordinary stream calls suspend the fiber instead of the process when a scheduler is running
— `stream_socket_accept`, `fread`/`fgets`/`stream_get_contents`, `fwrite`, `fclose`,
`stream_select`/`socket_select`, `sleep`/`usleep`, and everything layered on them (including
`file_get_contents('https://…')`). Network *setup* is async too — **name resolution included**:
`connect(2)` runs non-blocking, BOTH TLS handshake directions are driven through
`WANT_READ`/`WANT_WRITE` parks (client `SSL_connect` and server `SSL_accept` — so a TLS
server serves concurrent clients), and a hostname is resolved over the netpoller:
`/etc/hosts`, a per-run cache held by the scheduler, then A and AAAA queries across
**every** nameserver in `resolv.conf` (two attempts each, 2 s apiece, TC → retry over
TCP), falling back to the blocking `getaddrinfo` walk when none of that can answer.
Two spawned HTTPS fetches to DIFFERENT hosts run 0.17s vs 0.34s sequential.

`stream_set_timeout()` and `stream_socket_accept($srv, $timeout)` are honoured under the
scheduler: the wait is BOUNDED, so a hung peer no longer wedges the fiber forever (an
unbounded park was the old behaviour, and a timeout used to fall back to a blocking `poll(2)`
that stalled every other task).

The seam is `\Runtime\AsyncHook` (five callbacks installed by the scheduler — readable,
writable, close, bounded-readable, sleep; one null check per would-block when no scheduler is
running).

Plain streams **are** the API. `Async\read/write/accept/connect/close` also exist, bypassing
the stream layer for raw `recv`/`send` on the fd — worth roughly 2× when you are counting
syscalls, which is why the benchmark servers use them — but they carry no buffering and no
TLS, and mixing them with `fread`/`fwrite` on the same resource loses whatever the stream
layer had buffered. They live in an `@internal` section at the bottom of `prelude/async.php`;
reach for them only after measuring.

```php
async(function () {
    $a = spawn(fn() => file_get_contents('https://example.com/one'));
    $b = spawn(fn() => file_get_contents('https://example.com/two'));
    [$x, $y] = Async\awaitAll($a, $b);   // ~1 RTT, not 2
});
```

## ⚠ What is NOT async

**Regular-file I/O.** `O_NONBLOCK` is a no-op for regular files on both Linux and macOS, and
there is no thread pool / POSIX aio / io_uring here — so `file_get_contents('/path')`,
`fopen()` + `fread()` on a file handle, `stat`, and directory walks block the whole loop for
the duration of the call. Measured on a 64 MB page-cache-hot file: one `fread($h, 64MB)`
stalls every other task **15-25 ms**; reading it in 1 MB chunks with a yield between them
keeps the worst gap at **~2 ms**. So for anything big use

```php
$data = Async\readFile('/path/to/big');       // chunked + yields, cancellation-aware
Async\writeFile('/path/out', $data);
```

A fork+socketpair worker pool was measured against this and rejected: it copies every byte
through a socket, which for a hot read costs more than the read, and it only pays off on a
genuinely blocking (cold / networked) filesystem. Threads stay out entirely — non-atomic rc,
a non-thread-safe arena and a process-global exception slot.

`stream_select` under the scheduler is a POLLING park (poll(2) with a zero timeout plus an
exponential-backoff fiber sleep, 0.2 ms → 10 ms), not a reactor registration: a task holds one
per-fd waiter slot while a select waits on N fds. Nothing hot goes through it — the read /
write / accept paths use the reactor directly.

## Examples

```bash
examples/async/build.sh            # compile every demo (single files; no manifest, no library)

examples/async/smoke_bin           # structured concurrency: scopes, cancellation, awaitAll/Any
examples/async/chan_demo_bin       # channels + select
examples/async/async-io_bin        # HTTPS fetches: async vs channel vs sync wall time
examples/async/http_transparent_bin  # HTTP/1.1 keep-alive server on :8080 (prefork, plain streams)
examples/async/http_server_bin     # the same server on the raw Async\read/write path
examples/async/load_client_bin     # native async load client
examples/async/spawncost_bin       # spawn/join microbench
```

```bash
bin/manticore compile examples/async/tls_async_smoke.php -o /tmp/tls_smoke && /tmp/tls_smoke
```
is the NETWORK-dependent check (out of the offline suite): two HTTPS fetches to different
hosts, overlapped, plus `dns_get_record` over the parked UDP exchange.

Regression coverage lives in the suite: `tests/aot/cases/async_*.php`
(`bash tests/aot/run.sh -k async`). They are manticore-only — `php` has no `Io\Poll`, so
`difftest` skips them and their `expected/` is hand-written.

## Where it stands (macOS, 10-core, `wrk -t4`, plaintext keep-alive)

Single-core keep-alive ~64.5k rps (2-syscall/req floor). Multi-process (8-worker prefork)
same-box vs reference servers driven by the same `wrk`:

| server                     | req/s        |
|----------------------------|--------------|
| **manticore (8w prefork)** | **150–160k** |
| go (`net/http`, all cores) | 137–145k     |
| bun (1 core)               | 101–103k     |
| node (1 core)              | 63–64k       |

Caveats: the load generator shares the box (the server used only ~2.7/10 cores — a real
ceiling needs an off-box client); this server does minimal HTTP (single-byte routing, fixed
headers) — legitimate for the TechEmpower `plaintext` case, not a full framework.

## Not yet

**Reactor-native signal delivery.** Signals are BLOCKED and REAPED by a daemon task that
calls `pcntl_signal_dispatch()` every 50 ms, so handler latency is up to 50 ms. That is
fine for graceful shutdown and supervision (what the epic uses it for) but it is polling.
The fix is per-backend, not shared: kqueue has `EVFILT_SIGNAL` (ident = signo, filter -6),
Linux has `signalfd` (one fd the reactor watches like any other, then read 128-byte
`signalfd_siginfo` records); the `poll` fallback keeps the pump. Both need their own
measured constants, which is why it is listed here rather than half-done.

Also: reactor-native `stream_select` (a per-select waiter record) · `writev`/`io_uring` to
break the 2-syscall floor · DNS search-domain/`ndots` handling (a name needing a suffix
falls back to the blocking walk today) · off-thread file I/O · shared-memory
multithreading (a future compiler superset). See the async roadmap memory for the plan.

**`#[Async]`** — an attribute that wraps a function body in `async()` so `spawn()` works at
its top level, with the call yielding a `Task` of the return type. Unlike everything above it
is *not* implementable as a library: it needs the compiler to rewrite the function and to
carry a generic `Task<T>` through inference, which today has no way to express the binding.
Worth doing after the runtime settles, not before.
