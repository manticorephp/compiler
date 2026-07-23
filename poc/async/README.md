# Async PoC — Go-style green threads on Manticore

Proof of concept: a **pure-PHP** structured-concurrency runtime built on Manticore's
only two async primitives — **Fibers** (stackful, cheap) and **`Io\Poll`** (kqueue/epoll
readiness). No new compiler intrinsics; the scheduler, reactor, tasks, and I/O are all
ordinary PHP compiled to native. This is the shape of an extractable `manticore/async`
package.

## Model (not Promises)

Go concurrency, not `async`/`await`. Blocking-*looking* I/O that transparently suspends
the current fiber onto the reactor and resumes it when the fd is ready. **Structured
concurrency, no fire-and-forget** — every task lives in a `TaskGroup` scope that joins its
children and propagates the first failure.

```php
use function Async\{run, spawn, delay};
use Async\TaskGroup;

run(function () {
    TaskGroup::run(function (TaskGroup $g) {
        $g->spawn(fn() => work("a"));
        $g->spawn(fn() => work("b"));
    }); // returns only after both children finish; a throw propagates
});
```

## Layout (`src/`)

| file | role |
|------|------|
| `Scheduler.php` | run-queue + `Io\Poll` reactor + timer heap; the single event loop |
| `Task.php`      | a spawned unit — state, result, waiters |
| `TaskGroup.php` | structured scope: joins children, prunes settled, first-failure wins |
| `Context.php`   | ambient scope + cooperative cancellation |
| `api.php`       | `run` / `spawn` / `delay` |
| `io.php`        | raw non-blocking `recv`/`send` on the fd; accept/read/write/connect/close |
| `process.php`   | `workers($n)` — multi-process (fork), shared-nothing |

## Build & run

```bash
bin/manticore build poc/async/manticore.json     # -> poc/async/{smoke,echo,load,http}_bin
poc/async/http_bin                               # HTTP/1.1 server on :8080 (prefork)
poc/async/load_bin                               # native async load client
```

Standalone examples (no manifest): `capture.php` (per-task value capture),
`spawncost.php` (spawn/join microbench).

## Where it stands (macOS, 10-core, `wrk -t4`, plaintext keep-alive)

Single-core keep-alive ~64.5k rps (2-syscall/req floor). Multi-process (8-worker prefork)
same-box vs reference servers driven by the same `wrk`:

| server | req/s |
|--------|-------|
| **manticore (8w prefork)** | **148–149k** |
| go (`net/http`, all cores) | 137–145k |
| bun (1 core) | 101–103k |
| node (1 core) | 63–64k |

Caveats: the load generator shares the box (server used only ~2.7/10 cores — real ceiling
needs an off-box client); this server does minimal HTTP (single-byte routing, fixed
headers) — legitimate for the TechEmpower `plaintext` case, not a full framework.

## Not yet

Transparent I/O (auto-suspend inside `fread`/`fwrite`) · channels (CSP `select`) ·
`writev`/`io_uring` to break the 2-syscall floor · shared-memory multithreading (a future
compiler superset). See the async roadmap memory for the full plan.
