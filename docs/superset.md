# The superset — what Manticore adds on top of PHP

Manticore's design goal is that PHP **feels like PHP**: the interpreter is the oracle, and
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
| readiness | `stream_select` only | `Io\Poll` — kqueue / epoll / poll, edge-aware, a real reactor; a busy keep-alive connection never reaches it (2 syscalls/request, measured) |
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
  the full `resolv.conf` (`search`, `ndots`, `timeout:`, `attempts:`) with `/etc/hosts`
  consulted per CANDIDATE the way glibc runs nsswitch, then A and AAAA across every
  nameserver — attempt-major, so a dead first entry costs one timeout per round rather than
  two — TC → retry over TCP, with the blocking `getaddrinfo` walk as the last resort.
- **`fwrite()` takes a vector.** `fwrite($s, [$headers, $body])` on a plain socket is one
  `writev(2)`: no userspace concat of the two, and no second send. php's `fwrite` takes a
  string and nothing else, so the array form is superset — a non-socket sink or a TLS stream
  degrades to the concat, with an identical return value, which is what keeps it a drop-in.
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

Regular-file I/O has no readiness (`O_NONBLOCK` is a no-op for regular files on both targets),
so the common calls run on a blocking-offload pool: OS threads that execute a fixed set of libc
calls and never PHP (the rc, the arena and the exception slot are not thread-safe). See
`async.md` § Blocking calls run on a pool. Calls the pool does not cover still block, and there
is no io_uring (Linux-only would leave macOS behind). `Async\readFile()` /
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
| `#[TypeDef]` | the object ERASED to the one value it wraps: no allocation, no refcount, no class id. Carries a `repr` for a narrow property slot, a `__invoke` normaliser for refinement types, and a dedicated soundness gate (`CheckTypeDefs`) that refuses every site which would observe it as an object | none |
| `#[Manticore\Attr\RefOut]` | an out-parameter that is auto-vivified for the callee (how `preg_match($s, $p, $m)` fills `$m` natively) | the engine's own C-level by-ref |
| `#[Ffi\Library]` | which native library a binding links against — and what puts it on the link line, resolved via `pkg-config` / `<name>-config` / `-l<name>` | `ext/ffi` + `php.ini` |
| `#[Ffi\Symbol]` | the C symbol behind a PHP function, per target | `FFI::cdef` string |
| `#[Ffi\CType]` | the real C type of a return or parameter, from a closed vocabulary — PHP's `int` covers C's `char`/`short`/`int`/`long` alike and the wrapper needs the width. **Not cosmetic**: without it a C `int` `-1` reads as `4294967295` | C declaration |
| `#[Ffi\Weak]` | `declare extern_weak`, so a symbol absent on this target resolves to null instead of failing the link; Darwin's `-Wl,-U` allowance is derived from these | nothing |
| `#[Ffi\Variadic($fixed)]` | the named-param count of a C variadic callee, so the call gets a variadic type and its varargs land where `va_arg` looks | C declaration |
| `#[Manticore\Attr\CellArg]` | an element-CONSUMING `array` parameter: the call site boxes each element so a compiled-once stdlib callee always sees self-describing cells | nothing — the representation question does not exist in Zend |
| `#[Manticore\Attr\CompareKey]` / `CompareNone` / `Uncomparable` | declares how a class's objects compare under `==` / `<=>` — by the marked properties (also across classes), always equal, or never equal — for a class whose php original has its own compare handler ([attributes.md § Object comparison](attributes.md#object-comparison)) | the engine's per-class C compare handler |

**Checked, never lowered:** the ownership family `#[Ffi\Borrow]` / `BorrowMut` / `Take` /
`Give` / `StaticPtr`, the one with no analogue anywhere in PHP. The compiler enforces where
each may appear and that they do not contradict each other — including two rules that are
memory safety, since a PHP string is refcount-owned and a C buffer has no rc header — but
**nothing is freed on your behalf**. Letting a native pointer join the same rc discipline as a
PHP value remains a plan, not a feature.

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

### 5.1 Operator overloading — `Manticore\Overloadable` (`prelude/overloadable.php`)

Zend has no userland operator overloading (only internal classes such as `GMP` and
`BcMath\Number` hook operators in C). Here a class opts in by implementing the interface:

```php
final class Money implements \Manticore\Overloadable
{
    public function __operator(string $op, mixed $other, bool $swapped): static { /* … */ }
    public function __compare(mixed $other): int { /* … */ }
}
```

| source | compiled as |
|---|---|
| `$a + $b`, `-`, `*`, `/`, `%`, `**` / `pow()` | `$a->__operator('+', $b, false)`; object on the right (`2 - $a`) → `$a->__operator('-', 2, true)` |
| `-$a` | `$a->__operator('*', -1, false)` |
| `$a += $b`, `$a *= 2`, `++$a`, `--$a` | the same calls, assigned back (`++` passes `'+'`, `1`) |
| `<`, `<=`, `>`, `>=`, `==`, `!=`, `<=>` | `$a->__compare($b)` against 0 (operands flipped when the object is on the right) |
| `===`, `!==` | untouched — identity |

Rules and limits:

- **Static types only.** The rewrite is made by `InlineClosures::tryOperator` and fires only when
  an operand's *static* type is an object whose class (or an ancestor) implements
  `Overloadable`. Closure bodies are rewritten too. An operand reaching the operator as
  `mixed`/erased (`function plus(mixed $x, mixed $y) { return $x + $y; }`, `array_reduce` over an
  untyped array) is **not** rewritten and computes on the pointer instead of raising Zend's
  `TypeError` — open, repro `tests/aot/repro/crash/overloadable_erased_operand.php` (#263). Type
  the parameter or the container (`Number $x`, `Number[]`).
- Postfix `$a++` / `$a--` is accepted only as a whole statement; used as a value it is a
  compile error (it would have to answer the old value).
- The hooks are ordinary methods, so under Zend the class still loads but its operators raise
  `TypeError`; only `BcMath\Number` has a Zend oracle (below).
- Zero cost when unused: nothing is rewritten for a class that does not implement the interface.

`BcMath\Number` (`prelude/bcmath.php`, php 8.4) implements it and follows Zend's result scales and
exceptions (`DivisionByZeroError`, `ValueError` for a non-numeric string operand, `TypeError`
for an array). It is the only overloaded class with a php oracle. Known deviation: `round()`
takes the `PHP_ROUND_HALF_*` int, not the `RoundingMode` enum, which does not exist here yet.
The class is `readonly` and demand-gated: a program that never names `BcMath\` carries none of it.

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

## 7. Libraries — the superset you call, not compile

Everything above changes what the compiler accepts. These are plain PHP APIs: ordinary
classes and functions that exist because a compiled, single-binary runtime wants them and
Zend has no equivalent. All are **demand-gated** (rule 3): a program that never names the
namespace links none of it. None has a Zend oracle, so each is tested by hand-written
cases and specified by its guide; the generated class and function lists are in
[builtins.md](builtins.md#beyond-php--what-manticore-adds). API reference pages are
linked per entry.

### 7.1 `Manticore\Ds` — typed arrays, `Map`, `Set`, `Vec` ([docs/ds.md](ds.md))

Fixed-width arrays over one native buffer (`Int8Array` … `Int64Array`, `UInt8Array` …
`UInt64Array`, `Float32Array`, `Float64Array`, `BitArray`), a `ByteBuffer` with typed
reads and writes at a byte offset, and insertion-ordered `Map` / `Set` / `Vec` over
native hash tables. They exist because a PHP array pays a tagged cell per element
and coerces keys; here the element is stored at its width and keys are **strict**
(`2` and `"2"` are different keys, an object can be a key). Out-of-range values throw
instead of wrapping. A generated pure-PHP polyfill (`php tools/ds_polyfill.php`,
Composer package `manticorephp/ds`) lets the same file run under `php` for portability,
not speed. API:
[reference/Manticore/Ds/](https://manticorephp.github.io/compiler/reference/Manticore/Ds/).

### 7.2 `Http\` — an HTTP/1.1 server ([docs/http.md](http.md))

`(new Http\Server('tcp://…'))->serve(fn(Request $r): Response => …)` — the binary *is*
the server: no fpm, no worker pool to configure. One task per connection under an
`Async\TaskGroup`, keep-alive, chunked and streamed bodies, multipart uploads, static
files with `Range` / conditional requests, gzip, trusted-proxy handling, and
`->workers(N)` to prefork under a supervisor. Inside a handler the php builtins keep
working (`header()`, `setcookie()`, `http_response_code()`, plain `echo`), because the
request context is switched per fiber. It exists so a PHP service can ship as one
static binary with no separate web server. API:
[reference/Http/](https://manticorephp.github.io/compiler/reference/Http/).

### 7.3 `Http\WebSocket` — RFC 6455 server and client ([docs/websocket.md](websocket.md))

WebSocket on the same `Http\Server` and port (upgrade via `takeover()`), and an outbound
client (`Http\WebSocket\connect()`) for third-party servers. RFC 7692 permessage-deflate,
one reader per connection, `foreach` over incoming messages, sending from any task,
a callback layer and a `Hub` for broadcast. Checked by its own suite and by
Autobahn|Testsuite. API:
[reference/Http/WebSocket/](https://manticorephp.github.io/compiler/reference/Http/WebSocket/).

### 7.4 `Buffer\` — cursor-based byte buffers ([docs/http.md § Buffer](http.md#buffer))

`ByteBuffer` (bytes plus a read cursor, so consuming is not a quadratic `substr` of the
remainder), `Reader` (bounded reads over a stream through a shared buffer) and `Writer`
(buffered writes using one vectored `writev()`). `Http\` is written on them; they are
useful for any framing protocol. `indexOf()` answers `-1`, not `false`, on purpose: a
`int|false` return would make every call site a boxed cell. API:
[reference/Buffer/](https://manticorephp.github.io/compiler/reference/Buffer/).

### 7.5 `Manticore\Sapi` — a compiled binary *is* the SAPI

The per-request seam that lets a server written in PHP reuse php's request API. Two
calls (`requestBegin`, `requestEnd`) install the GPC arrays and collect the status line,
headers and cookies the handler produced; with no request active the CLI answers
exactly as php's CLI SAPI does (`headers_list()` is `[]`, `http_response_code()` is
`false`). The state is held per `Async\Task`, so concurrent requests cannot read each
other's `$_COOKIE`. It is the layer `Http\` stands on; use it directly only to embed
another protocol. API:
[reference/Manticore/Sapi/](https://manticorephp.github.io/compiler/reference/Manticore/Sapi/).

### 7.6 `Process\` — fork, workers, supervision

`Process\fork/pid/ppid`, `Process\workers(int)` and `Process\supervise(int, callable)`
(restart a crashed worker, forward `SIGTERM` to the group). Described in §1.4 because it
sits beside `Async\`; listed here for the API link:
[reference/Process/](https://manticorephp.github.io/compiler/reference/Process/).
`Async\`'s own API:
[reference/Async/](https://manticorephp.github.io/compiler/reference/Async/); `Io\Poll`:
[reference/Io/Poll/](https://manticorephp.github.io/compiler/reference/Io/Poll/).

### 7.7 `Async\Pool` — a bounded object pool ([docs/async.md](async.md))

`new Async\Pool($factory, $max, …)` hands objects out FIFO with `acquire` / `release` /
`with` / `discard`, optional `reset`, `ping`, `maxLifetime` and `idleTimeout`, and
`Pool::pdo($dsn, …)` wires the PDO session reset and ping. It exists because a
concurrent service must bound its database connections per process. With `->workers(N)` the bound is
per process, so the database sees up to `N × max`.

### 7.8 Low-level pointer builtins ([docs/ffi.md](ffi.md))

Not libraries but global functions with no PHP equivalent, the glue of an FFI binding:
`peek_i8/i16/i32/i64/u8/u16/u32/f64`, `poke_*`, `ptr_offset`, `ptr_to_int`,
`int_to_ptr`, `cstr_to_str`, `fn_to_ptr` (a PHP function as a C callback). They work on
`\Ffi\Ptr` addresses and are as unchecked as the C they sit next to. See ffi.md before
using one outside a binding. API:
[reference/Ffi/](https://manticorephp.github.io/compiler/reference/Ffi/).

Also here and not worth a section of its own: `fwrite($sock, [$head, $body])` as one
`writev(2)` (§1.3), `Fiber::setStackSize()`, `Manticore\Overloadable` (§5.1), the
`Resource` class ([differences.md](differences.md#resources-are-objects)), and the
stdlib's ICU / libcurl / libz-backed extensions, which follow Zend's API and so are
parity work rather than superset.

---

## 8. Runtime knobs

No `php.ini`. The environment variables that exist:

| variable | effect |
|---|---|
| `MANTICORE_MEMORY` | memory model — `hybrid` / `rc` / `arena` |
| `MANTICORE_ASYNC_WATCHDOG` | loop-hog watchdog threshold, in ms |
| `MANTICORE_FIBER_STACK` | bytes of stack per fiber (default 1 MiB). Zend spells this `fiber.stack_size` in php.ini, which we have no mechanism for |
| `MANTICORE_PRELUDE` | where the prelude lives (a binary built to a temp path cannot find it argv0-relative) |
| `MANTICORE_STDLIB` / `_O` / `_SIG` | override the stdlib object / signature the driver links |
| `MANTICORE_PROFILE`, `MANTICORE_DEBUG_VERIFY`, `MANTICORE_TYPECHECK`, `MANTICORE_REFLECT_REPORT`, `MANTICORE_UNKNOWN_PROP_TRACE` | compiler diagnostics |
| `MANTICORE_ARENA_ARRAYS`, `MANTICORE_EMPTY_SINGLETON` | allocation strategies, both **on** by default — set `0` to opt out |
| `MANTICORE_HOME`, `MANTICORE_REPO`, `MANTICORE_REF`, `MANTICORE_SRC` | read by `install.sh`, not by the compiler: where to install, and what to build from |

---

## 9. Designed, not built

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
- **`io_uring` file I/O** — Linux-only; the offload pool (§1.5) covers regular files on both targets.

---

## Where each piece is verified

| area | how |
|---|---|
| `Async\`, `Io\Poll`, `Fiber` | `tests/aot/cases/async_*.php` (`bash tests/aot/run.sh -k async`) — manticore-only, hand-written expected |
| transparent I/O bounds | `async_write_timeout`, `async_stream_timeout`, `async_accept_idle_park` |
| accept classification | `async_accept_errno` — asserted through the errno **selector**, never a raw number, so it reads the same on both hosts |
| resolver | `dns_resolv_conf`, `async_dns_resolver` — text-fixture parsers, no network |
| FFI / attributes | the stdlib itself is the test: every `Runtime\Libc` binding rides them |
| operator overloading | `operator_overload`, `bcmath_operators`, `bcmath_number` — hand-written expected; `BcMath\Number` is also checkable against php 8.4 |
| modules | `tests/aot/cases/manifest_app/` (a directory fixture, not a `-k`-selectable case) plus the compiler's own self-host build |
| `Manticore\Ds`, `Buffer\`, `Http\`, `Http\WebSocket` | `tests/aot/cases/ds_*`, `http_*`, `ws_*` — hand-written expected; Ds also has a Zend-hosted polyfill as its oracle; WebSocket adds the Autobahn Testsuite gate |
| everything with a Zend answer | `tools/difftest.sh` |

The network-dependent check lives outside the suite:
`bin/manticore compile examples/async/tls_async_smoke.php` — two overlapped HTTPS fetches to
different hosts plus `dns_get_record` over the parked UDP exchange.
