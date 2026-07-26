# Async — Go-style green threads on Manticore

A **pure-PHP** structured-concurrency runtime over Manticore's two async primitives —
**Fibers** (stackful, cheap) and **`Io\Poll`** (kqueue/epoll readiness). No new compiler
intrinsics: the scheduler, reactor, tasks, channels and I/O are ordinary PHP compiled to
native.

It ships in **`prelude/async.php`** and is demand-gated: a program that never names
`Async\` carries none of it (naming it also forces `\Fiber` and `Io\Poll` on). Nothing to
install, nothing to link — `use function Async\spawn;` is enough.

This directory holds the examples and benchmarks.

## Model (not Promises)

Go concurrency, not `async`/`await`. Blocking-*looking* I/O that transparently suspends the
current fiber onto the reactor and resumes it when the fd is ready.

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
- **Cancellation is real.** The first failure in a scope cancels its siblings by
  deregistering them from the reactor/timer and resuming them with an
  `Async\CancelledException` *at their suspend point* — not by setting a flag and hoping.
  `Context::throwIfCancelled()` is the extra checkpoint for a CPU-bound loop that never
  suspends.
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
| `Async\awaitAll(Task ...): array` | all results by input position; fail-fast |
| `Async\awaitAny(Task ...): mixed` | first success, else `AggregateError` |
| `Async\delay(float $seconds)` | suspend without blocking the loop |
| `Async\channel(int $cap = 0): Channel` | CSP channel; 0 = unbuffered rendezvous |
| `Async\select(Channel[]): [idx, value, ok]` | receive from whichever is ready first |
| `Async\workers(int $n): int` | fork N shared-nothing workers (call before `async()`) |
| `Task::await(): mixed` | wait for one task |
| `Task::awaitWithin(float): mixed` | …with a deadline; cancels the task on expiry |
| `TaskGroup::run(callable)` | what `group()` wraps |
| `TaskGroup::token(): CancellationToken` | the read-only half of this scope's cancellation |
| `TaskGroup::cancel()` | the write half |
| `Context::token()/isCancelled()/throwIfCancelled()` | the calling task's scope, ambiently |
| `Context::deadline()/remaining()` | the effective deadline, ambiently |
| `Async\accept/read/write/connect/close` | raw fd-level socket I/O (the hot path) |

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
`CancelledException` at its suspend point.

## Transparent I/O

Ordinary stream calls suspend the fiber instead of the process when a scheduler is running
— `stream_socket_accept`, `fread`/`fgets`/`stream_get_contents`, `fwrite`, `fclose`, and
everything layered on them (including `file_get_contents('https://…')`). Network *setup* is
async too: DNS aside, `connect(2)` runs non-blocking and the TLS handshake is driven through
`WANT_READ`/`WANT_WRITE` parks, so two spawned HTTPS fetches overlap end-to-end rather than
serialising on their handshakes.

The seam is `\Runtime\AsyncHook` (three callbacks installed by the scheduler; one null check
per would-block when no scheduler is running).

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
`fopen()` + `fread()` on a file handle, `stat`, and directory walks block the whole loop.
Keep them off the hot path. (Go hides this behind extra OS threads; doing the same needs a
thread-safe arena + rc, which is its own epic.)

Blocking DNS is the remaining network gap: `getaddrinfo(3)` still runs synchronously. The
pieces for an async resolver already exist (`__mc_dns_query` in `src/Runtime/Stdlib/Dns.php`
speaks DNS over a UDP `\Resource`, which the netpoller already suspends on).

## Examples

```bash
poc/async/build.sh            # compile every demo (single files; no manifest, no library)

poc/async/smoke_bin           # structured concurrency: scopes, cancellation, awaitAll/Any
poc/async/chan_demo_bin       # channels + select
poc/async/async-io_bin        # HTTPS fetches: async vs channel vs sync wall time
poc/async/http_transparent_bin  # HTTP/1.1 keep-alive server on :8080 (prefork, plain streams)
poc/async/http_server_bin     # the same server on the raw Async\read/write path
poc/async/load_client_bin     # native async load client
poc/async/spawncost_bin       # spawn/join microbench
```

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

Async `getaddrinfo` · send-`select` (receive-select only so far) · `writev`/`io_uring` to
break the 2-syscall floor · off-thread file I/O · shared-memory multithreading (a future
compiler superset). See the async roadmap memory for the full plan.

**`#[Async]`** — an attribute that wraps a function body in `async()` so `spawn()` works at
its top level, with the call yielding a `Task` of the return type. Unlike everything above it
is *not* implementable as a library: it needs the compiler to rewrite the function and to
carry a generic `Task<T>` through inference, which today has no way to express the binding.
Worth doing after the runtime settles, not before.
