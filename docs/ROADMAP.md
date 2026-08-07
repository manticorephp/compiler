# Manticore — status & roadmap

**Single source of truth for "where the compiler is and what's next."**

_Last updated: 2026-07-30 · branch `main` · HEAD `dea8a63`._

## Current state

Pure-PHP, self-hosting PHP→native AOT compiler. `bin/manticore` compiles its own `src/` to a
byte-identical fixpoint, and the runtime is emitted as LLVM IR from PHP.

**Self-hosting is done.** That was the north star; it is now the floor. What replaced it is
**real-world applications** — the quality corpus is no longer the compiler alone but
third-party PHP compiled through it (symfony/console is the current driver), and the oracle is
still Zend: if `php` runs it, `tools/difftest.sh` must agree byte-for-byte.

**Gates:** `tests/aot/run.sh` · `tools/difftest.sh` (parity vs PHP 8.5) ·
`tools/selfhost_fixpoint.sh` (fixpoint byte-identical · self-host suite · rebuild stability
5×2) · `tools/docker/run_tests.sh --gate` (Linux). Counts move every session — run them
rather than trusting a number written here.

**Build:** `bin/build` (self-host — the normal loop), `bin/build --seed` (cold Zend
bootstrap), `bin/build --verify` (+ the gate). `bin/compile` is the cold-bootstrap fallback
only.

⚠ **`bin/build` green says nothing about `tools/selfhost.sh`.** The manifest build compiles
`src/Runtime` as a LIBRARY with a flattened namespace; the self-host path takes everything as
ONE module. They diverge on emitted symbol names, and only the stability gate covers the
second. Corollary: **never ship a compiler fix together with tree code that needs that fix** —
the previous generation then cannot build the tree at all, and only a cold seed recovers.

⚠ **A new codegen builtin used by the stdlib needs `bin/build --seed`.** The previous
generation does not know the symbol, so the stdlib `.o` build dies on an undefined symbol.

### Recently completed (2026-07)

- **`ext/pdo` + `pdo_sqlite` — a database layer** (`docs/pdo.md`). `PDO` / `PDOStatement`
  are a thin facade over an internal driver seam, so a future mysql/pgsql driver (a socket
  with pack/unpack, no FFI) drops in without touching them — SQLite is not a server, which
  is why this one is FFI. Demand-gated on a `PDO` mention, so a binary links `-lsqlite3`
  only if it uses it. No trampolines: driving prepare/step directly avoids `sqlite3_exec`'s
  callback entirely. No SQL scanner either — `sqlite3_bind_parameter_index()` already
  resolves `:name` with sqlite's own quoting and comment rules.
  SQLITE_BUSY is waited out through `\Runtime\AsyncHook` (never `sqlite3_busy_timeout`,
  which sleeps in a C frame and stalls the netpoller).
  Divergences, each documented: `getCode()` is the driver's int because
  `Throwable::getCode(): int` is a contract here; `bindParam()` binds by value at bind time;
  `bindColumn()` / `FETCH_BOUND` throw — all three for the same missing zval reference.
  ⛔ `FETCH_OBJ` / `FETCH_CLASS` / `FETCH_INTO` / `FETCH_LAZY`, and `fetch()` with no
  argument under the default `FETCH_BOTH`, are blocked on the ERASED-VALUE work, not on the
  driver: a value returned through a `mixed` channel whose arms have different shapes is not
  self-describing. `docs/pdo.md` carries three minimal repros that never mention PDO.
- **`ext/curl` — an HTTP client** (`docs/curl.md`). The easy API, `curl_multi_*` and
  `curl_share_*`, bound to libcurl through FFI and demand-gated, so a binary links
  `-lcurl` only if it calls one of them. `CURLOPT_WRITEFUNCTION` and its three siblings
  take real Closures: `fn_to_ptr` needs a string literal, so libcurl holds one of four
  fixed trampolines and carries the handle **id** in the `void*` it hands back. One
  `#[Variadic(2)]` binding serves every `curl_setopt` option class, because libcurl
  encodes the C type in the option NUMBER.
  Not implemented, each with a named throw: `CURLFile`/`CURLOPT_MIMEPOST` multipart,
  the `*_BLOB` options, `CURLMOPT_PUSHFUNCTION`, and a real `CURLINFO_CERTINFO`.
  Every float `CURLINFO` is read through its `_T` sibling — this build cannot read a
  C `double` out of memory (no `peek_f64`, no bitcast builtin, no `unpack('d')`), which
  is the one gap worth closing if a caller ever needs a genuine double from C.
- **`Http\` — an HTTP/1.1 server** (`docs/http.md`). A handler is
  `callable(Request): Response`; one process serves many requests at once, and php's
  `header()`/`setcookie()`/`http_response_code()`/`headers_sent()`/`echo` work inside it
  per request — as do `$_GET`/`$_POST`/`$_COOKIE`/`$_SERVER`/`$_SESSION` under
  `compat(true)`. Streamed request and response bodies, chunked framing,
  `Expect: 100-continue`, keep-alive with pipelining, and every limit answered by a
  precomputed refusal. `Buffer\ByteBuffer`/`Reader`/`Writer` underneath.
- **`serialize` / `unserialize` + magic methods** — `__serialize`/`__unserialize`,
  `allowed_classes`, `__PHP_Incomplete_Class`, `__debugInfo`, `var_export` of objects, and
  `__get`/`__set`/`__isset`/`__unset`/`__call` firing on an **erased** receiver.
- **One ownership contract for conditionals** — `?:`, `??`, ternary and `match` share
  `Compile\Mir\CondOwn`, so the arms and their consumer agree on who owns the result.
- **Generators: a resumed `try` owns its landing pad** — `Generator::throw()` no longer lands
  in the caller's catch.
- **The full `array_*` surface** — all 59 functions, including `array_multisort`,
  `array_merge_recursive` and the internal pointer.
- **The async remainder** — 1 MiB fiber stacks with `MANTICORE_FIBER_STACK`, non-quadratic
  channel waiter queues, checked `mmap`/`calloc`, `posix_getrlimit`/`setrlimit`, and a
  per-case deadline in the harness so a liveness bug fails the suite instead of hanging it.
- **Dynamic resolution** — dynamic function names, `new $cls`, `$cls::method()`, `$o->$m()`,
  `$o->$p`, `$obj instanceof $cls`, and Reflection through Tier 3.

## Tier 1 — correctness

| Gap | Repro | Today | Want |
|---|---|---|---|
| Integer overflow wraps | `PHP_INT_MAX + 1` | `PHP_INT_MIN` (two's complement) | promote to float, as php does. Needs value-range analysis to know which statically-int locals can overflow |
| `/` exact-int on variables | `$a/$b`, both int, divisible | `float` | `int`. Literal `6/2` already folds to `int(3)`; the variable case cascades through a numeric cell — low value |
| `echo` / concat of `INF`/`NAN` | — | renders lowercase | uppercase, as php does. `var_dump` is already correct. **No repro exists — write one first** |

`['a'] === ['a']` compares pointers rather than contents, and `extract()` is unimplemented
(dynamic symbol-table writes the typed frame does not model). `compact()` works.

## Tier 2 — semantic depth

### Magic methods — what is knowingly not done

`serialize`/`unserialize`, `__debugInfo`, `var_export` of objects, and erased-receiver
dispatch for `__get`/`__set`/`__isset`/`__unset`/`__call` are **done**. What is left, and why:

1. **None of the hooks fire for an INACCESSIBLE DECLARED member.** All of them gate on "the
   class has no slot for this name". php also fires when the slot EXISTS but is
   private/protected out of the accessing scope. Manticore enforces no visibility at all, so
   such an access simply succeeds. Closing it needs a scope model in the emitter (compare the
   frame's class prefix against `PropertyMeta::$visibility` / `$declaringClass`) — separate
   work, not a dispatch problem.
2. **`__call` on an erased receiver is rerouted only when NO class declares the method.** The
   mixed case — some classes declare it, others answer through `__call` — needs two different
   argument lists in one switch, and the call's arg emission is built against the resolved
   callee's signature.
3. **`is_callable()` on an erased value answers true for ANY object.** Narrowing it to classes
   declaring `__invoke` needs a class_id probe, and a Closure has NO class descriptor (slot 0
   is its function pointer), so the probe would start answering false for the common case.
   Needs the closure header, not a switch.
4. **`__sleep` / `__wakeup` are ignored.** php calls them from serialize/unserialize when
   `__serialize`/`__unserialize` are absent.
5. **`unset($o->declaredProp)` is a no-op**, so the "unset it so `__get` fires again" idiom
   does not work.
6. **`&__get` (return by reference) is unsupported** — the magic call yields an i64 cell.
7. **`Stringable` is not auto-added** to a class declaring `__toString`, so
   `class_implements()` / `getInterfaceNames()` do not report it as php 8 does.
8. **Uninitialized typed properties serialize as their zero value.** Manticore zero-fills
   every slot, so `class P { public int $x; }` writes `1:{s:1:"x";i:0;}` where php writes
   `0:{}`. Needs an init bitmap in the object header — an object-ABI change.
9. **`R:` is never emitted.** It marks a php REFERENCE, and a Manticore array carries no
   is_ref bit, so there is no runtime fact to emit it from. It is accepted on input as a
   value copy.
10. **`(array)$obj` yields the dynamic-property BAG only**, where php returns the declared
    properties too. `var_dump` / `serialize` / `var_export` compose the two themselves.
11. **A bare `array` property hint erases its element**, so the elements of `public array $a`
    read raw — `var_dump` and `var_export` print ints as denormal floats. A `@var int[]` on
    the same property is correct. This is the parked element-repr work.
12. **php 8.5 clone-with does NOT run the readonly guard here** — and should not: the RFC's
    purpose is to let a clone reinitialize a readonly property. Noted because it looks like a
    missing check. There is no `php` oracle for it (8.5.8 does not parse the syntax), so it is
    a superset feature.

### Other semantic gaps

- **`goto` into a loop body** is unsupported. Plain forward and backward `goto` work.
- **`ReflectionEnum` does not exist** — it was built and reverted. Every other Reflection
  class ships (`prelude/reflection.php`).
- **Static properties are external-linkage globals only**, so two compilation units cannot
  disagree about one.
- **Element representation is half done.** The array flags word carries an element-repr
  nibble that release / retain / COW read, but the erased element channel is not yet a cell,
  so a concrete `string[]` parameter fed a cell-element array still misreads.

## Tier 3 — infrastructure

- **`.sig` schema 2 ships classes, interfaces, enums and constants** (`tests/libs/classes` +
  `tools/libclass_smoke.sh`). What remains:
  - **`trait`s and generic (`@template`) classes still do not cross.** Both need method
    BODIES on the far side — a trait because it is copy-paste into the using class, a
    generic because each binding is reified from source. They are recorded in the `.sig`
    as `"unsupported"` so the diagnostic can say why.
  - **`instanceofMatchIds` / `descendantClassIds` remain closed-world and `catchAcceptsAll`
    still fails open** — now over the UNION of local and imported classes, which is the
    whole program for a library-first build order, but not for a plugin loaded later.
  - **A class descriptor is `linkonce_odr` and the two modules can emit different
    bytes for it** (the library's rmeta field is null unless the library itself reflects).
    The application object is linked first, so its richer copy wins — deterministic given
    how the link line is built, but an invariant rather than a guarantee. The fix is the
    descriptor extension below.
- **EPIC: per-class function pointers in the class descriptor.** `__mc_json_enc` lives in
  `manticore_stdlib.o`, whose class table is empty, so `(array)$obj` inside it yields `[]`
  and `json_encode` of ANY object — imported or local — answers `{}`. Same shape for
  `__manticore_tagged_to_str`. Extend `{ i64 class_id, ptr drop_fn, ptr rmeta }` with
  `props_fn` / `tostr_fn` / `debug_fn`, generated once by whichever module OWNS the class,
  so the definition travels with the class and a generic walker in `stdlib.o` reaches an
  application class without knowing its name. Bumps `MemoryAbi::VERSION` (⇒ one
  `bin/build --seed`) and lets four of the five `LowerPrelude::*ObjectSrc()` generators go.
- **No dependency resolution, no build cache, no packaging bootstrap.** `MANTICORE_HOME`,
  `~/.manticore/cache` and a `compiler_abi` field appear in
  [`design/module-system.md`](design/module-system.md) but nowhere in `src/`. Manifest targets
  and Composer source discovery work; transitive dependency fetch does not.
- **The ABI version is not surfaced.** `MemoryAbi::VERSION` is 7 and `manticore version`
  prints only `manticore 0.6.0`, so a vendored `.o` cannot detect a mismatch.
- **`dump-mir --after=<pass>`** is described in [`design/mir.md`](design/mir.md) but not
  implemented.
- **Cycle collector: manual trigger only**, and it does not scan static or global roots. A
  threshold heartbeat and a safe-point trigger are unbuilt.
- **Monomorphize has no `$cell` fallback.** `Monomorphize.php` calls it "future, Phase 3"; the
  "every monomorphized function keeps exactly one name-addressable `$cell` entry" invariant in
  [`design/monomorphization.md`](design/monomorphization.md) is aspirational, not upheld.
- **No CI, no prebuilt binaries.** Every install compiles from source.

## Tier 4 — performance

Everything already beats Zend (2–50× on compute; assoc arrays beat php outright). The
remaining levers:

- **IR volume is the build-time lever** — `clang -O2` is ~66% of `bin/build`. Emitting less IR
  beats optimising the front end.
- SSO / interning for dynamic small strings; property-bag literal hashes.
- Harden the gated `TypeCheck` pass (`MANTICORE_TYPECHECK=1`) toward on-by-default — it would
  have caught the `str_replace`-array misuse at compile time instead of as a runtime SIGBUS.
- Array / JSON / sort helpers sit at roughly 2× php and are competing with hand-tuned C —
  that is close to the ceiling, not a bug.

## How to build the plans (the method that works here)

1. **Probe-matrix first.** One minimal repro per feature, diffed against `php`, before
   committing to a design.
2. **Find the convergent root.** Monomorphization was *the* root behind a dozen erasure
   symptoms. Ask "is there one fix that collapses several rows?" before building.
3. **Phase and gate hard, every phase:** suite + difftest + fixpoint + stability + `--seed`.
   Never batch risky changes. A "random transient" can be a real latent bug — chase it.
4. **Dual-validate the Zend seed AND the native build** — they diverge on strings, floats and
   by-ref. Some bugs only surface in the native self-build, and an emitter fix needs TWO
   generations before its effect is real.
5. **php-faithful signatures; root cause over workaround.** No reverts, no workarounds.
6. **Self-host is the gate; real programs are the probes.**

## Design references

Living reference:

- [`../README.md`](../README.md) — what it is, what it needs, how to use it.
- [`superset.md`](superset.md) — everything with no Zend oracle, and what that costs.
- [`install.md`](install.md) — host dependencies and platform support.
- [`async.md`](async.md) — structured concurrency and transparent I/O.
- [`memory.md`](memory.md) — the memory model as a user sees it (`--memory`, env knobs).
- [`modules.md`](modules.md) — the manifest, `.sig` interfaces, Composer projects.
- [`generics.md`](generics.md) — docblock `@template`, bounds, reified `@var C<T>`.
- [`ffi.md`](ffi.md), [`attributes.md`](attributes.md) — native binding and the attribute set.
- [`http.md`](http.md), [`curl.md`](curl.md) — the HTTP server and the HTTP client.

Design notes:

- [`design/mir.md`](design/mir.md) — the IR, the type lattice, the pass pipeline.
- [`design/memory-abi.md`](design/memory-abi.md) — **the stone tablet**: layout, refcount
  encoding, destructor order, cycle-collector ABI.
- [`design/refcount-cow.md`](design/refcount-cow.md) — why refcount + CoW, not a GC.
- [`design/type-system-v2.md`](design/type-system-v2.md),
  [`design/unknown-cell-soundness.md`](design/unknown-cell-soundness.md) — the cell / union /
  NaN-box type system and the erased-representation soundness work.
- [`design/monomorphization.md`](design/monomorphization.md) — the generics / erasure engine.
- [`design/generators-and-pointers.md`](design/generators-and-pointers.md) — generators.
- [`design/module-system.md`](design/module-system.md) — the module design behind `modules.md`.
- [`design/build-and-packaging.md`](design/build-and-packaging.md) — packaging.
- [`design/late-static-binding.md`](design/late-static-binding.md) — LSB lowering.
- [`design/async-attribute.md`](design/async-attribute.md) — a designed, deliberately unbuilt
  `#[Async]`.
