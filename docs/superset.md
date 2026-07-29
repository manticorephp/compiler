# The superset — what Manticore adds on top of PHP

Manticore's north star is that PHP **feels like PHP**: the interpreter is the oracle, and
`tools/difftest.sh` compares our output against it case by case. Everything on that side of
the line is *parity* work, and it is not what this document is about.

This document is the other side: the surface that has **no oracle**, because `php` cannot run
it at all. A green difftest says nothing about any of it. That single fact shapes every rule
below.

## The rules the superset lives under

1. **No oracle ⇒ its own tests.** A superset feature's `tests/aot/expected/*.out` is
   hand-written and reviewed, never captured from `php`. Difftest classifies such a case as
   manticore-only *only when `php` produces no stdout for it* — so those cases must print
   nothing before their first Manticore-only call, or a skip silently becomes a DIFF.
2. **Inert under Zend wherever possible.** Generics are docblocks, FFI bindings and layout
   directives are attributes: `php` ignores all of it, so the same file still runs under the
   interpreter. That is what keeps a Manticore project a PHP project rather than a fork of the
   language. Where a feature genuinely cannot be inert (`Async\`, `Io\Poll`), it is confined to
   its own namespace and demand-gated.
3. **Zero cost when unused.** The async runtime is not linked into a program that never names
   `Async\`; the cycle collector costs nothing until `gc_collect_cycles()` is called; the
   watchdog is one float compare per resume when off. A superset that taxes the programs that
   ignore it is a tax on parity.
4. **No php.ini, no PECL, no C extension ABI.** Configuration is the manifest and, for the few
   runtime knobs, environment variables. Anything a C extension would have done is either FFI
   or a manifest target.

---

## 1. Concurrency

The largest piece, and the one with the least Zend to compare against: `php` has `Fiber`, and
nothing else here.

### `Async\` — structured concurrency (`prelude/async.php`, [docs/async.md](async.md))

Pure PHP over two primitives; **no new compiler intrinsics**. What makes it a superset rather
than a library you could publish on Packagist is that the primitives underneath it are ours:

| what | Zend | here |
|---|---|---|
| green threads | `Fiber` (userland scheduler, no I/O integration) | `Fiber` on native `fcontext` (~2.8× a Zend fiber switch), stack pooled, guard page |
| readiness | `stream_select` only | `Io\Poll` — kqueue / epoll / poll, edge-aware, a real reactor |
| blocking I/O | blocks the process | suspends the fiber (§1.3) |
| task ownership | none | every task belongs to a scope; no fire-and-forget |
| cancellation | none | delivered as `CancelledException` **at the suspend point**, sticky, re-raised at every later suspend |
| deadlock | `rc=0`, silently | `DeadlockException` with the full task table |
| signals | `pcntl_signal_dispatch()` you must call | a daemon task parked on `EVFILT_SIGNAL` / `signalfd(2)` |

Guarantees, the API table and the idioms are in [docs/async.md](async.md). The parts worth
naming *as superset* — nothing in PHP expresses them:

- **Scope-owned tasks.** `async()` opens a root scope; `TaskGroup::run()` opens a child. A
  scope does not return until its children are joined, and the scope lives on the task, so two
  concurrent `group()` calls cannot adopt each other's children.
- **Cancellation as a value.** The scope is the source (`$g->cancel()`), a `CancellationToken`
  is its read-only half — a helper can observe cancellation without being handed the power to
  cause it. `Context::token()/deadline()/remaining()/value()` read the calling task's scope
  ambiently, which is where PHP would otherwise reach for a global.
- **Deadlines compose by tightening only.** A 30 s inner `timeout()` inside a 2 s outer one
  dies at 2 s.
- **`shield()`** — the one thing that holds cancellation back, so cleanup that must itself
  suspend (a close frame, a 503) can run.
- **CSP channels + `select`.** `Channel` is an `IteratorAggregate`, so consumption is a
  `foreach` that ends when the channel is closed and drained; `next(): Received` is the comma-ok
  form for when `null` is a legal payload. `select` / `selectNow` / `selectWithin` are Go's
  three forms, returning a `Selected` object because PHP has no multi-return.
- **`Semaphore` / `Mutex` / `Once`** — a critical section that may suspend in the middle,
  which is exactly what a `lock` cannot be in a language with no scheduler.

### 1.2 Diagnostics you cannot build from outside the engine

A cooperative loop's two failure modes are "hung" and "one task is holding it", and neither is
observable from library code:

- `Async\dump()` — every live task, what it is parked on (`io-read fd=9 +deadline`), and
  **where it was spawned**. The `file:line` is folded in *by the compiler* at the `Async\` call
  site, so it works with no annotation; `->named('http')` only adds a label.
- `Async\dumpOn(SIGQUIT)` — the same table out of an **already hung** process.
- `Async\watchdog(50.0)` / `MANTICORE_ASYNC_WATCHDOG=50` — names the task that held the loop
  too long, after the fact (a cooperative loop cannot preempt), rate-limited per task.
- `Async\stats()` — `spawned`/`settled`/`cancelled`/`wakes`/`reactor_waits`/`timer_fires`/
  `watchdog` plus the `live`/`ready`/`io_parked`/`timers` gauges. `live` is the gauge to alert
  on; `wakes` climbing with wall time rather than with work is what a spin looks like.
- `Async\failure()` — **which task** raised the failure that escaped, and where it was spawned.
  The exception itself cannot carry this: it is rethrown by whoever joined the task, so it
  arrives with the joiner's file and line. First failure only; the cancellation wave that
  follows is not the culprit.
- `Fiber::setStackSize()` / `MANTICORE_FIBER_STACK` — bytes of stack per fiber, default 1 MiB.
  An overflow faults into the guard page and is NAMED (`fiber stack overflow`) by a handler on
  an alternate stack; every other fault passes through untouched.
  MEASURED, not chosen: at 40 000 concurrent tasks on Linux, 8 MiB costs 6.55 GiB of RSS and
  1 MiB costs 0.65 GiB, with 512 and 256 KiB costing exactly the same — flat below 1 MiB,
  ten-fold above it. Two mappings go per fiber (the guard page splits the VMA), so a stock
  `vm.max_map_count` of 65530 caps a process near 32 000 concurrent tasks whatever the size.

### 1.3 Transparent I/O — the same stdlib, different blocking behaviour

This is the piece users notice least and depend on most: `fread`, `fwrite`,
`stream_socket_accept`, `stream_select`, `sleep`, `file_get_contents('https://…')` and
everything layered on them **suspend the fiber instead of the process** when a scheduler is
running. Plain streams *are* the async API; there is no `async fread`.

Under it, and all superset:

- **Network setup is async too** — non-blocking `connect(2)`, both TLS handshake directions
  driven through `WANT_READ`/`WANT_WRITE` parks (so a TLS *server* serves concurrent clients),
  and name resolution over the netpoller: `/etc/hosts`, a per-run cache held by the scheduler,
  `search`/`ndots` expansion from `resolv.conf`, then A and AAAA across every nameserver
  (two attempts each, TC → retry over TCP), with the blocking `getaddrinfo` walk as the last
  resort.
- **Every wait is bounded.** `stream_set_timeout()` sets the stream's timeout for reads *and*
  writes; a park that expires records `timed_out` and reports a short read/write. An unbounded
  park is a liveness hole, not a feature — a peer that stops reading would otherwise wedge a
  fiber, its scope and its fd forever.
- **`accept(2)` failures are classified**, not retried blindly: would-block parks, a peer that
  vanished retries, resource exhaustion (EMFILE/ENFILE/ENOBUFS/ENOMEM) backs off on a timer,
  and anything else is reported. Under EMFILE the pending connection stays queued, so a
  level-triggered listener stays readable — re-arming readiness there is a hot spin that
  starves every sibling.
- **`stream_select`/`socket_select` are reactor-native** — one park, one wake-up per readiness
  edge, instead of a backoff loop.
- **The seam is one interface**: `\Runtime\AsyncHook`, a handful of callbacks the scheduler
  installs. With no scheduler running it is one null check per would-block, which is why the
  stdlib pays nothing for being async-aware.

### 1.4 Process model — `Process\`

`Process\fork/pid/ppid`, `Process\workers(int)`, `Process\supervise(int, callable)` sit
**beside** `Async\`, deliberately not inside it: none of them runs a scheduler, and forking
must happen before a reactor exists. `supervise()` forks N workers, restarts one that crashes
and forwards `SIGTERM` to the group — the shape a real service needs, and the reason the
pcntl layer exists at all.

### 1.5 What is deliberately NOT async

Regular-file I/O blocks the loop: `O_NONBLOCK` is a no-op for regular files on both targets,
and there is no thread pool (rejected: non-atomic rc, a non-thread-safe arena, a process-global
exception slot) and no io_uring (Linux-only would leave macOS behind). `Async\readFile()` /
`Async\writeFile()` chunk and yield. Saying this plainly is part of the superset's contract:
a runtime that claims "everything is async" and blocks anyway is worse than one that names
the exception.

---

## 2. Compile-time directives — attributes ([docs/attributes.md](attributes.md))

PHP attributes are inert metadata to Zend, which is exactly what makes them the right carrier
for compiler instructions: the file still parses and runs under `php`.

| attribute | what it buys | Zend equivalent |
|---|---|---|
| `#[Manticore\Attr\Struct]` | a class with **no object header** — a value laid out like a C struct | none |
| `#[TypeDef]` | a named layout / type alias the compiler reasons about | none |
| `#[Manticore\Attr\RefOut]` | an out-parameter that is auto-vivified for the callee (how `preg_match($s, $p, $m)` fills `$m` natively) | the engine's own C-level by-ref |
| `#[Ffi\Library]` | which native library a binding links against | `ext/ffi` + `php.ini` |
| `#[Ffi\Symbol]` | the C symbol behind a PHP function, per target | `FFI::cdef` string |
| `#[Ffi\CType]` | the C type of a return/param — **not cosmetic**: a C `int` return is not sign-extended by every libc, so `-1` reads as `4294967295` without it | C declaration |
| `#[Ffi\Borrow]` / `BorrowMut` / `Take` / `Give` / `StaticPtr` | ownership across the FFI boundary, so the compiler knows who frees what | nothing — you get a leak or a double free |

The ownership family is the part with no analogue anywhere in PHP: it lets a native pointer
participate in the same rc discipline as a PHP value instead of being an opaque handle the
programmer must remember to free.

---

## 3. FFI without an extension ([docs/ffi.md](ffi.md))

A binding is a PHP function with attributes; the call is a direct native call, not a
marshalling layer. There is no `ext/ffi`, no `FFI::cdef` string to parse at runtime, no
`php.ini` to enable. Libraries are linked **statically** into the output binary, so a compiled
program has no runtime dependency on them — which is what lets `preg_*` ride host PCRE2 and
TLS ride OpenSSL while the binary stays self-contained.

The trade named honestly: opaque handles are `\Ffi\Ptr`, and memory that crosses the boundary
obeys the ownership attributes above rather than the garbage collector.

---

## 4. Modules and builds ([docs/modules.md](modules.md))

PHP has no build system, so this whole layer is superset:

- **`manticore.json`** — a manifest of *applications* (an entry point → a binary) and
  *libraries* (a source tree → a `.o` + a `.sig`). `bin/manticore build` is the whole build.
- **`.sig` module interfaces** — a compiled library's exported signatures, so a consumer type
  checks against it without re-reading its source. (Known limit: a `.sig` carries **functions
  only** — classes, interfaces, traits, enums and constants do not cross a library boundary yet.)
- **Composer discovery** — a `vendor/` tree resolves as sources to compile, not as an autoload
  map to evaluate at runtime.
- **Distribution** — a single static binary; nothing to install on the target, no runtime, no
  extension list.

---

## 5. Types ([docs/generics.md](generics.md), [docs/design/type-system-v2.md](design/type-system-v2.md))

The type system is inferred and then *used* — for layout, for unboxing, for monomorphization —
rather than checked and discarded:

- **Docblock generics first** (`@template`, bounds, defaults, `@extends`/`@implements`,
  generic traits), because a docblock is inert under Zend. Inline `<…>` is an extension for
  code that has already committed to Manticore.
- **Reified class generics** — a real specialized class where you need one.
- **Implicit generics / monomorphization** — a function taking a callback is specialized per
  concrete closure with no annotation at all. This is the difference between a callback costing
  a dynamic dispatch and costing nothing.
- **`bin/manticore analyze`** — the static checks a compiler can make that an interpreter never
  gets the chance to: unsound patterns, hazards that only appear natively.
- **`dump-ast` / `dump-mir` / `dump-llvm-mir` / `dump-sig`** — every stage of the pipeline is
  inspectable. ⚠ The dumps do not link the stdlib, so a call into it resolves as `unknown`;
  read the final binary when that matters.

---

## 6. Memory ([docs/memory.md](memory.md))

PHP gives you a refcount you cannot see and a GC you cannot steer. Here the model is a choice:

- **hybrid (default)** — escape analysis routes each value to an arena or to refcounting.
- **rc** — deterministic, immediate frees.
- **arena** — bulk reclaim at scope/program exit, no per-object frees; ideal for a
  compile-and-exit tool (Manticore's own batch runs).
- `gc_collect_cycles()` — a synchronous **Bacon–Rajan** cycle collector, opt-in and zero cost
  until called. Current limits: manual trigger only, and it does not scan static/global roots.

Selected with `MANTICORE_MEMORY`; finer-grained control (a per-function `#[Arena]`, explicit
scopes) is designed, not wired.

---

## 7. Runtime knobs

No `php.ini`. The environment variables that exist, and nothing more:

| variable | effect |
|---|---|
| `MANTICORE_MEMORY` | memory model — `hybrid` / `rc` / `arena` |
| `MANTICORE_ASYNC_WATCHDOG` | loop-hog watchdog threshold, in ms |
| `MANTICORE_FIBER_STACK` | bytes of stack per fiber (default 1 MiB). Zend spells this `fiber.stack_size` in php.ini, which we have no mechanism for |
| `MANTICORE_PRELUDE` | where the prelude lives (a binary built to a temp path cannot find it argv0-relative) |
| `MANTICORE_STDLIB` / `_O` / `_SIG` | override the stdlib object / signature the driver links |
| `MANTICORE_PROFILE`, `MANTICORE_DEBUG_VERIFY`, `MANTICORE_TYPECHECK`, `MANTICORE_REFLECT_REPORT`, `MANTICORE_UNKNOWN_PROP_TRACE` | compiler diagnostics |
| `MANTICORE_ARENA_ARRAYS`, `MANTICORE_EMPTY_SINGLETON` | allocation experiments |

---

## 8. Designed, not built

Kept here so the boundary stays honest:

- **`#[Async]`** — an attribute that turns a call into a spawned `Task<T>`. The only piece of
  the async story that cannot be a library: it needs the compiler to split the function and to
  type the call site. The typing half already works (`Type::typeArgs` +
  `InferCalls::genericReturnType()` resolve `Task<T>` with no reification), so what remains is
  a lowering pass and three decisions about methods, inheritance and calling one outside
  `async()`. See [docs/design/async-attribute.md](design/async-attribute.md).
- **`#[CompileTime]`** — evaluate a function at compile time.
- **Shared-memory threads** — a future compiler superset, and a much larger one: it invalidates
  the non-atomic rc, the arena and the process-global exception slot that everything above is
  built on.
- **Off-thread / `io_uring` file I/O** — see §1.5 for why not yet.

---

## Where each piece is verified

| area | how |
|---|---|
| `Async\`, `Io\Poll`, `Fiber` | `tests/aot/cases/async_*.php` (`bash tests/aot/run.sh -k async`) — manticore-only, hand-written expected |
| transparent I/O bounds | `async_write_timeout`, `async_stream_timeout`, `async_accept_idle_park` |
| accept classification | `async_accept_errno` — asserted through the errno **selector**, never a raw number, so it reads the same on both hosts |
| resolver | `dns_resolv_conf`, `async_dns_resolver` — text-fixture parsers, no network |
| FFI / attributes | the stdlib itself is the test: every `Runtime\Libc` binding rides them |
| modules | `manifest_app`, plus the compiler's own self-host build |
| everything with a Zend answer | `tools/difftest.sh` |

The network-dependent check lives outside the suite:
`bin/manticore compile examples/async/tls_async_smoke.php` — two overlapped HTTPS fetches to
different hosts plus `dns_get_record` over the parked UDP exchange.
