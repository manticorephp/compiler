# Error-return exceptions

Status: design + probes (2026-11-11). Issue #648.

## Why

A PHP `throw` that a `catch` takes costs 1.35 µs on Linux arm64 and 3.1 µs on
macOS (19.6 µs before the Darwin fast-unwind patch); php 8.5 does the same in
0.3–0.6 µs. PHP code uses exceptions for control flow — validation, parsers,
`try { … } catch (NotFound)` around lookups, Laravel/Symfony error paths — so
the cost is user-visible, and the throw-path leak tests are the slowest cases of
the suite.

The cost is the model, not a bug. Zero-cost (Itanium/DWARF) unwinding makes
the non-throwing path free and the throw expensive by design: per frame the
unwinder finds the frame's unwind tables (on Darwin a dyld lookup), interprets
CFI to restore callee-saved registers, calls the personality, which parses the
LSDA, and jumps into a landing pad; a cleanup pad then re-enters the unwinder.
Zend does none of that: its frames live on the VM stack, `throw` sets
`EG(exception)` and the executor checks it after each opcode.

## Goal

A throw caught N frames up costs a few nanoseconds per frame — measured
0.003 µs for 1 frame, 0.010 µs for 10 (probe below), i.e. 100× faster than
php — while the non-throwing path pays at most a few percent.

Non-goals: C++ interop (no binary links a C++ runtime); unwinding through
foreign C frames (an FFI callback's exception is deferred, see Boundaries).

Anti-goals: any change of PHP semantics (destructor order, `finally`,
chained exceptions, uncaught fatal output) — difftest stays the gate; a
happy-path slowdown above ~3% on the benchmarks in `docs/perf` / the
self-build time.

## Model

One process-wide slot `@__mc_exc` (ptr, null = no exception in flight), the
same slot every thread of PHP code would use — there is exactly one: the
blocking-offload pool threads never touch PHP state (EmitLlvmModule
`offloadRuntime`). Fibers switch it with `@__mir_thrown` (the per-fiber save
table in EmitLlvmFiber, offset 56 today).

- `throw $e` stores `$e` (+1) into `@__mc_exc` and branches to the current
  pad (or the frame's propagate block).
- After every call that may throw, the caller tests the slot:
  `%x = load ptr, ptr @__mc_exc` / `icmp ne` / `br i1 %x, label %pad, label %cont`
  (`!prof` weights mark the pad cold). This is exactly the line `ehLower`
  turns into `invoke … unwind label %pad` today, so the transform has one home.
- A call that today needs no pad (mark `;!e0`) now needs the check too: on a
  set slot the frame returns at once through its **propagate block**
  (`ret <ty> undef` / `ret void`). The caller never reads a return value when
  the slot is set.
- A pad starts by **taking** the exception: `%ex = load @__mc_exc`,
  `store null`. Cleanup code (drops, destructors — PHP code that checks the
  slot itself) runs with the slot clear. A cleanup pad ends by putting it
  back (`store %ex`) and branching to propagate — the `resume` today. A catch
  pad hands `%ex` to the existing dispatch (`@__mir_thrown`, class-id match,
  rethrow = store + br) unchanged.
- `@main` / the top-level statement list: a set slot after a statement runs
  the uncaught fatal (`emitUncaughtHandler`). Every frame above has already
  run its cleanups on the way back, which is the order php prints destructor
  output before `PHP Fatal error:  Uncaught …`.

What stays: every pad body, the drop sets, call-temp lists (`;!t` →
`@__mc_tmpdrain`), the chained-destructor logic, the catch dispatch. What goes:
`invoke`, `landingpad`, the personality and LSDA parser, `UnwindRuntime`
except the uncaught handler, `-static-libgcc`/libunwind at link time, the
Darwin fast-unwind patch.

## Where calls are checked

Every `;!e`-marked line, i.e. what the marking already considers may-unwind
(`ehLineMayThrow`, `ehRaiseCallee`). That set is conservative
(`InferEffects`: every call form is MAY_THROW). Phase 2 narrows it: a
`nothrow` function summary — no `throw`, no may-throw call, no `new` of a
class with a throwing constructor — inferred bottom-up over the call graph,
written to the `.sig` for stdlib functions, so `strlen`-class callees cost no
check. Indirect / virtual / `$f()` calls stay checked.

## Boundaries

- **Stdlib / prelude**: compiled by the same compiler into `lib/*.o`, same
  model. A memory-ABI-like change: pass 1 (old compiler, old model inside the
  compiler binary) builds the new compiler; pass 2 rebuilds `lib/*.o` with the
  new binary. No program mixes models, so no pin raise and no seed — the
  same argument as the v8 → v9 ABI bump.
- **C callees** (libc, PCRE, OpenSSL, FFI functions) never throw: no check.
- **C calling PHP** (FFI callbacks, `qsort`-style comparators, signal
  dispatch if any): the thunk returns to C with the slot set; C carries on;
  the PHP caller of the C function checks the slot after it returns and
  propagates. php would unwind through C here; we defer. Document it in
  `docs/ffi.md`.
- **Generators**: a throw inside the body propagates out of `resume` like any
  frame; `$gen->throw()` stores the slot at the yield resume point. The
  generator frame's own pads are ordinary blocks, which removes the
  generator exclusion the call-temp work had to make.
- **Fibers**: the fiber entry returns with the slot set → the trampoline
  switches back to the resumer, which sees the slot set after `resume()` /
  `start()` and propagates. Slot is saved/restored per fiber.

## Cost (probe, macOS arm64, clang -O2)

`fib(32)`, a check after both recursive calls (worst case: tiny body, two
calls):

| variant | time |
|---|---|
| no check | 6.9–7.0 ms |
| global slot | 6.9–7.8 ms (0–11 %) |
| `swifterror` register (swiftcc) | 8.7–8.8 ms (+25 %) |

Throw caught after 1 / 10 frames: 0.003 / 0.010 µs (global slot), 0.003 /
0.012 µs (swifterror).

Decision: global slot. `swifterror` keeps the error in a register but forces
`swiftcc` on every PHP function, which costs more than the load it saves, and
changes the ABI of every function pointer and FFI thunk. Real PHP function
bodies (refcounting, type checks) dwarf a predicted load+branch, so the
expected overhead on real code is well under the fib worst case; probe 2
measures it on the compiler itself.

## Probes before the plan

1. **Spike**: `MANTICORE_EH=ret` switch in `ehLower` + pads + `emitThrow` +
   `@main`, enough for the exceptions/finally/destructor cases in
   `tests/aot/cases/*throw*`, `*catch*`, `*finally*`, `*unwind*`. Measure
   `tb.php` (throw 0–5 frames) against php and the zero-cost build.
2. **Happy-path cost**: self-build time and the bench corpus with the spike
   vs without; binary size (no `.gcc_except_table`/`__eh_frame` growth vs the
   extra branches).
3. **nothrow narrowing**: how many `;!e` lines a bottom-up summary removes in
   the compiler's own IR.

## Rollout

1. Land the model behind no switch once the suite and difftest are green on
   Linux + macOS (no long-lived dual path: two exception models double every
   pad emitter).
2. nothrow summaries + `.sig` bit.
3. Delete `UnwindRuntime` unwinding, the personality, libunwind/libgcc_eh
   link flags.

## Left to the builder

Exact IR of the propagate block per return type; how `ehPhiRetarget` maps the
new edges (same as invoke today); block layout/`!prof` weights; the generator
`resume` contract details.
