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

Everything here is **superset**: `php` has `Fiber` and nothing else of it, so `difftest` cannot
check a single line — these cases carry hand-written expected output instead.
[docs/superset.md](superset.md) catalogues that whole surface (concurrency, attributes, FFI,
modules, types, memory) and the rules it lives under.

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
| `Async\dump(): string` | every live task, what it is parked on, and where it was spawned |
| `Async\dumpOn(int ...$signals)` | print that on a signal — `kill -QUIT <pid>` on a hung process |
| `Async\watchdog(float $ms)` | name the task that HOLDS the loop longer than $ms |
| `Async\stats(): array` | engine counters (spawned/wakes/reactor_waits/…) |
| `Async\failure(): string` | which task raised the failure that escaped, and where it was spawned |
| `Fiber::setStackSize(int)` | bytes of stack per fiber (`MANTICORE_FIBER_STACK` does the same) |
| `MANTICORE_FIBER_GUARD=0` | one mapping per fiber instead of two — twice the task ceiling, no named overflow |
| `fwrite($s, [$hdr, $body])` | vectored write — one `writev(2)`, no concat |

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

### When it hangs, or stalls

Three questions, three answers — none of which needs a rebuild:

```php
echo Async\dump();
// async: 3 live task(s), 2 parked on I/O, 1 on timers, 0 ready
// * #1 at server.php:14 ready
//   #4 "http" at server.php:31 io-read fd=9 +deadline awaited
//   #7 near server.php:44 timer
```

Every task line carries **where it was spawned**. The compiler folds `file:line`
into the `Async\` call that created it, so this works with no annotation at all;
`->named('http')` only adds a label on top. `$g->spawn(…)` inside a scope cannot be
pinned exactly (the receiver's class is not known until type inference), so it
reports the scope's line as `near …`. A `DeadlockException` embeds the whole table.

```php
Async\dumpOn(SIGQUIT);        // then `kill -QUIT <pid>` on an ALREADY-hung process
```

And for the opposite failure — nothing hangs, everything is just *slow*, because one
task is holding the loop:

```php
Async\watchdog(50.0);         // or MANTICORE_ASYNC_WATCHDOG=50, no code change
// async: watchdog — task #3 "report" at app.php:88 held the loop 214.3 ms (limit 50 ms)
```

That is the failure mode a cooperative loop cannot report by itself: regular-file
I/O (blocking by design here), a CPU-bound stretch with no suspend point, a library
falling back to a blocking call. It is reported *after* the stall — a cooperative
loop has no way to preempt — and each task reports its first breach and then only a
doubling, so a knowingly CPU-heavy worker cannot flood the log. Off costs one float
compare per resume.

`Async\stats()` is the machine-readable half — `spawned`, `settled`, `cancelled`,
`wakes`, `reactor_waits`, `timer_fires`, `watchdog`, plus the `live` / `ready` /
`io_parked` / `timers` gauges.

And for the third failure — something threw, and the trace points at the wrong
place:

```php
try { async(fn() => …); }
catch (\Throwable $e) { fwrite(STDERR, Async\failure()); }
// task #7 "worker" at jobs.php:31 raised RuntimeException: no such row
//   in scope near jobs.php:12
```

A child's exception is rethrown by whoever *joins* it, so it reaches you carrying
the joiner's file and line; the task that actually failed is not in the trace at
all. It cannot be put there — mutating a user's throwable is impossible, and
wrapping it would break `catch (RuntimeException)`. So the provenance is recorded
beside the exception, at the moment of failure, and read back with `Async\failure()`.
It names the FIRST real failure only: everything after it is the cancellation wave,
and blaming a task that was merely swept up is worse than saying nothing. With the
watchdog on it also goes to STDERR, on the same channel.

### How much a task costs

A fiber is 1 MiB of stack by default — `MANTICORE_FIBER_STACK=<bytes>`, or
`Fiber::setStackSize()` from code, both taking effect for fibers created afterwards.
Stacks are `mmap`'d with a `PROT_NONE` guard page below them (`MANTICORE_FIBER_GUARD=0`
drops it — see below), pooled on termination, and paged in lazily, so what a parked
task actually holds is far less than its size.

The default is measured, not assumed (`tools/fiber_ceiling.php`). At 40 000
concurrent tasks on Linux arm64:

| stack | RSS | virtual |
|---|---|---|
| 8 MiB | 6.55 GiB | 313 GiB |
| 1 MiB | 0.65 GiB | 39 GiB |
| 512 KiB | 0.65 GiB | 20 GiB |
| 256 KiB | 0.65 GiB | 10 GiB |

Flat below 1 MiB, ten-fold above it. Raise it for deeply recursive work; lower it
only to save address space, because it will not save memory. macOS shows no such
step, which is exactly why the number had to come from both hosts.

An overflow is named rather than guessed at: running off the bottom of a fiber stack
faults into the guard page, and a handler on an alternate stack prints
`manticore: fiber stack overflow (raise MANTICORE_FIBER_STACK)` before letting the
process die exactly as it would have. Any other fault is passed straight through, so a
genuine null dereference still looks like one.

The ceiling that arrives first is neither of those columns: each fiber costs **two
mappings** (the guard page splits the VMA), so a stock Linux `vm.max_map_count` of
65530 stops a process near 32 000 concurrent tasks whatever the stack size. Raise
`vm.max_map_count` if you need more; container defaults are often higher already
(Docker Desktop ships 262144).

If you cannot raise the sysctl, **`MANTICORE_FIBER_GUARD=0`** skips the `mprotect`
and spends one mapping per fiber instead of two, which doubles that ceiling. It is a
real trade, not a free win: without the guard page an overflow is an ordinary fault
in whatever the kernel put below the stack, so the named message above is gone and a
deep enough recursion corrupts rather than dies. Default on; resolved once per
process, so a program cannot end up holding a mix of guarded and unguarded stacks.

Beyond the stack, a task's other cost is its **arena**: every fiber allocates on its
own, and its first chunk is 4 KiB, doubling to a 64 KiB ceiling as the task actually
allocates. A task that never allocates holds ~25 KiB; one that does holds ~31 KiB
(macOS arm64, 1 MiB stacks, 10 000 and 20 000 concurrent tasks). A flat 64 KiB
minimum chunk used to make that second number ~42 KiB.

### Bounded concurrency

```php
$pages = Async\mapConcurrent($urls, fn($u) => file_get_contents($u), 10);   // 10 at a time
$sem = new Async\Semaphore(4);
$sem->withPermit(fn() => work());
```

**An accept loop needs one.** It is the one place a scheduler will happily let you
spawn without bound: `while (true) { $g->spawn(serve(accept())); }` turns a
connection burst into unbounded tasks, fds and memory, and the first thing to fail is
something unrelated. Take the permit **before** the accept — then the queue stays in
the kernel backlog, where it belongs, instead of in your heap.

```php
$gate = new Async\Semaphore(256);
Async\group(function (Async\TaskGroup $g) use ($server, $gate) {
    while (true) {
        $gate->acquire();                                  // BEFORE the accept
        $conn = stream_socket_accept($server);
        if ($conn === false) { $gate->release(); continue; }
        $g->spawn(function () use ($conn, $gate) {
            try { serve($conn); } finally { $gate->release(); }   // in the CHILD
        });
    }
});
```

`Async\stats()['live']` is the gauge to alert on, and `Async\dump()` names the tasks
when it climbs. There is deliberately **no built-in ceiling on tasks per scope**:
`spawn()` is the wrong place to fail (the exception would land in the acceptor, the
one task that has to survive an overload), a hard error turns overload into a crash
instead of flow control, and `live` counts timers and reporters that share nothing
with the work being limited. The policy — park, shed, or answer 503 — belongs to the
application, and a Semaphore owned by the scope that owns the work already expresses it.

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
a per-run cache held by the scheduler, then the `search` list and `ndots` rule from
`resolv.conf` (so `db`, `redis`, `service.namespace` — the names compose and kubernetes hand
you — resolve here instead of falling through to the blocking walk). `/etc/hosts` is
consulted for EVERY candidate the search list produces, the way glibc runs nsswitch, so a
short name matches a fully-qualified hosts line. Then A and AAAA queries across **every**
nameserver, `options attempts:` rounds over the list with `options timeout:` seconds each
(2 and 2 by default — glibc waits 5 s, which is too much of a request budget to hand a
silent resolver), TC → retry over TCP, falling back to the blocking `getaddrinfo` walk when
none of that can answer. A search walk stops the
moment NO server answers: a dead resolver must not be multiplied by the suffix count.
Two spawned HTTPS fetches to DIFFERENT hosts run 0.17s vs 0.34s sequential.

**Every wait is bounded.** `stream_set_timeout()` is the STREAM's timeout, as in php — reads
*and* writes — and `stream_socket_accept($srv, $timeout)` is honoured under the scheduler.
On expiry the operation reports a short read/write with `stream_get_meta_data()['timed_out']`
true, never an exception. Unbounded parks were the old behaviour on both sides, and each was
a liveness hole rather than a missing feature: a peer that stops reading wedged the writing
fiber forever — the task never settled, its scope never closed, the fd was never released —
and a timeout used to fall back to a blocking `poll(2)` that stalled every other task. The
default when nothing is set is 60 s, php's `default_socket_timeout`.

**`accept(2)` failures are classified.** A would-block parks; a peer that vanished between its
SYN and our accept (`ECONNABORTED`/`EPROTO`/`EINTR`) retries immediately, since no readiness
edge is coming for a connection that is already gone; resource exhaustion
(`EMFILE`/`ENFILE`/`ENOBUFS`/`ENOMEM`) backs off on a timer and keeps serving what is already
open; anything else is reported. The overload case is why this matters: `accept(2)` fails
while the pending connection **stays queued**, so a level-triggered listener stays readable —
re-arming readiness is a hot spin that starves every sibling task and never even trips the
watchdog, because it suspends on every iteration. It shows up only as `stats()['wakes']`
climbing with wall time instead of with connections.

The seam is `\Runtime\AsyncHook` — eleven callbacks installed by the scheduler:
`waitReadable` / `waitWritable`, their bounded variants `waitReadableFor` /
`waitWritableFor`, `onClose`, `sleeper`, the DNS-cache pair `dnsGet` / `dnsPut`, and the
`select` trio `selectAdd` / `selectWait` / `selectDone`. With no scheduler running it costs
one null check per would-block.

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

`stream_select` / `socket_select` are reactor-native: the task registers a record per fd,
parks once, and releases the lot on the way out — so a readiness edge costs one wake-up
instead of up to 10 ms of backoff, and a select no longer competes for the per-fd waiter slot
that a real reader holds. The non-blocking form (`0, 0`) never touches the reactor at all.

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

Single-core keep-alive ~64.5k rps, at a **two-syscall floor** — counted, not assumed.
Multi-process (8-worker prefork) same-box vs reference servers driven by the same `wrk`:

| server                     | req/s        |
|----------------------------|--------------|
| **manticore (8w prefork)** | **150–160k** |
| go (`net/http`, all cores) | 137–145k     |
| bun (1 core)               | 101–103k     |
| node (1 core)              | 63–64k       |

Caveats: the load generator shares the box (the server used only ~2.7/10 cores — a real
ceiling needs an off-box client); this server does minimal HTTP (single-byte routing, fixed
headers) — legitimate for the TechEmpower `plaintext` case, not a full framework.

### The two syscalls, counted

`strace -c -f` on one worker (Linux arm64), the same run at two request counts so the
constant startup cost subtracts out:

| syscall | 2 000 requests | 6 000 requests | per extra request |
|---|---|---|---|
| `recvfrom` | 2 008 | 6 008 | **1.00** |
| `sendto` | 2 001 | 6 001 | **1.00** |
| `epoll_pwait` | 2 | 2 | 0 |
| `epoll_ctl` | 1 | 1 | 0 |
| `accept` / `fcntl` / `close` | 10 / 34 / 15 | 10 / 34 / 15 | 0 |

Two syscalls per request, and **nothing else scales with load at all**. The reactor is
entered twice in a whole run: the optimistic `recv` finds the next pipelined-or-not request
already buffered in the kernel, so a busy keep-alive connection never parks — and because
`ensureWatcher` only runs when a task actually parks, those connection fds are never
registered with epoll in the first place (`epoll_ctl` 1 is the listener). The `+8` on
`recvfrom` is one per connection: the read that reports the peer's close.

To repeat it: `strace -c -f -o out.txt ./server` under `docker run --user root
--cap-add=SYS_PTRACE --security-opt seccomp=unconfined`, then subtract two runs. The
toolchain image has no `pkill`/`pgrep` — use `killall` or `kill $!`, or the script waits
forever for a server nothing stopped.

Signal delivery is reactor-native: a blocked-and-pending signal is a readiness event on
both hosts — `EVFILT_SIGNAL` on kqueue, `signalfd(2)` on Linux — so the dispatch task parks
on the reactor instead of ticking every 50 ms, and an idle process with a handler
registered wakes up never. Nothing reads the signalfd: its readability is the hint, and
`pcntl_signal_dispatch()`'s own `sigwait(2)` consumes the signal, so there is one dispatch
path on both hosts. Where neither is available (the portable `poll` backend) the 50 ms tick
is still there.

## Not yet

Also: off-thread file I/O · shared-memory multithreading (a future compiler superset).

**Below two syscalls per request.** Those two are `recvfrom` + `sendto` and nothing else
(measured above). `writev(2)` is already here — `fwrite($s, [$hdr, $body])` sends headers and
body in one syscall — but merging writes cannot help a request that only makes one. The floor
moves for exactly two reasons, and neither is free:

- **Pipelining.** Parse every complete request sitting in one `recv`, answer with one vectored
  write of N responses: 2/N syscalls per request. Portable, and it needs nothing from the
  compiler — but only a client that pipelines sees it.
- **Completion-based I/O (`io_uring`).** The win is not a new `Io\Poll` backend. Our seam is
  *readiness* — the hook says "ready", the caller then makes the syscall — and io_uring pays
  when the operations themselves are submitted in batches, so one `io_uring_enter` covers the
  recv and send of every ready connection in a turn. That means a second I/O path and a
  different hook shape, Linux-only, with macOS left on the readiness path. Worth a design
  document before a line of it.

**`#[Async]`** — an attribute that turns a call into a spawned `Task` of the function's
return type. The only piece here that cannot be a library: it needs the compiler to split
the function and to type the call site. Designed, not built — `docs/design/async-attribute.md`
has the whole shape, including the finding that the typing half is already possible
(`Type::typeArgs` + `InferCalls::genericReturnType()` resolve `Task<T>` with no
reification), so what is left is a contained lowering pass plus three decisions about
methods, inheritance and calling one outside `async()`.
