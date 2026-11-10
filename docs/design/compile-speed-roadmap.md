# Compile speed — roadmap

Status: plan, 2026-11-10. Goal: a small program compiles in 0.1–0.3 s (Go's
class), a large app several times faster than today, and CI time follows.

## Where the time goes today

Per program (`MANTICORE_PHASE_TRACE=1`, `-O2`, macOS arm64):

| program | front-end | clang | link | IR |
|---|---|---|---|---|
| trivial (`inc_dec`) | 0.4 s | 0.5 s | 0.1 s | 0.76 MB |
| `named_args_collected_by_variadic` | 0.6 s | 3.8 s | 0.07 s | 3.2 MB |
| `reflection_new_instance_prelude_class` | 1.2 s | 11.4 s | 0.17 s | 12 MB |

Every 20th AOT case (138): 364.5 s, of which clang 282.1 s (77 %) and
front+emit 63.0 s. A CI amd64 shard spends ~9.4 s per case.

Three facts drive the plan:

1. **Most of the work is not the user's code.** 60–80 % of a program's IR is
   prelude/runtime bodies, re-lowered, re-inferred and re-optimized in every
   program. clang time follows IR bytes.
2. **The prelude is injected by FILE and pruned after emission**, so a program
   pays front-end and IR for every function of every file it touches.
3. **Process overhead is real at fine granularity**: clang costs ~42 ms before
   it reads a byte and ~10 ms per module even inside one process.

## What other compilers do (and what we take)

| technique | who | what we take |
|---|---|---|
| compile a library once, reuse by content | Go (`GOCACHE`), Rust (`.rlib`), C++ modules | prelude bodies compiled once per variant (E1) |
| ship the compiled body AND an inlinable/specializable form | Swift (`@inlinable` SIL), Rust (MIR in metadata, `share-generics`) | keep specialization local, reuse the generic object (E1) |
| don't redo the front-end | Julia sysimage/pkgimage, GHC `.hi`, Swift `.swiftmodule`, C/C++ PCH | prelude snapshot (E3) |
| emit less | every fast compiler; LLVM time ∝ IR | function-level prelude reachability, outlined dispatch (E2) |
| cheap backend for debug/test | Rust+Cranelift, Zig self-hosted, Go SSA, TPDE (LLVM-IR in, 10–20× faster than `-O0`), copy-and-patch | test tier (E4) |
| fine-grained incremental | Rust queries, Zig incremental, Bazel/ccache | content-addressed parts (E1), later front-end incremental |
| parallel codegen | Rust CGUs + ThinLTO, Swift WMO threads, Go per-function | parts compile on all cores (E1) |
| no process per unit | rustc/swift-frontend in-process LLVM, Bazel workers | in-process LLVM only if E1–E4 fall short (E5) |

## Epics, in order of payoff per effort

### E1 — Shared generics (implemented on branch `shared-generics`; `docs/design/shared-generics.md`)
Result on the 138-case sample: empty cache wall 89 % / CPU 105 %, warm CPU 43 %,
runtime geomean 1.000. Remaining: release seed, libraries and large apps.
Large prelude bodies compiled once per variant, reused from the object cache.
Spec/plan: `docs/superpowers/specs|plans/2026-11-09-shared-generics*`.
Done: mechanical roots — identity 46.5 → 59.8 %, variant hits 17.5 → 67.3 %
over 138 programs. Open: per-part overhead (cold CPU 124–151 % of today in the
first measurement; fixed by fewer/larger parts, grouped clang runs, leaner
`available_externally`), CI cache persistence, a **seed** of common variants so
a first-ever compile mostly hits. Expected: warm clang 2–2.4× faster; CI reruns
near-zero clang for unchanged programs (only changed parts miss).

### E2 — Emit less IR
- Function-level reachability for the prelude BEFORE lowering (today: whole
  files in, prune after emission). Wins front-end and clang together.
- Outline whole-program dispatch (`dynf` arms, `vdisp`, erased-interface
  chains) into per-module helpers called from bodies, not inlined into them.
- Cold paths (raises, type errors) out of line.
Gate: `tests/ir_budget` ceilings tightened per step; runtime bench ±2 %.

### E3 — Prelude snapshot (sysimage analog)
Parse + lower each prelude file once per compiler build and load the result.
Inference stays per program (it specializes prelude bodies on purpose), so the
snapshot is pre-inference MIR. First step: measure how much of the 0.4–1.2 s
front-end is prelude parse/lower vs inference; build only if it pays.

### E4 — Optimization tiers
- `--profile=test`: `-O1` (clang ~3× faster than `-O2` on the sample) for the
  AOT suite; `-O2` stays on one CI row and in releases (catches -O2-only UB).
- Prelude/cold functions `optnone`/`minsize` where a bench shows no cost.
- Later: TPDE as the debug/test backend — it takes LLVM IR, so nothing in the
  emitter changes. To check first: Mach-O support (ELF-first), arm64 coverage,
  output parity on the suite.

### E5 — Process overhead (only if E1–E4 leave it on the table)
In-process LLVM through FFI (parse IR from memory, reuse one TargetMachine,
compile parts in forked workers): removes the ~42 ms/process and most of the
~10 ms/module. Cost: a libLLVM dependency (present in the Linux images, not in
Apple's toolchain — macOS would need Homebrew llvm).

### E6 — Front-end inference on large apps
Fewer `InferTypes` reruns and semi-naive worklists (see the compile-time
profiles of 2026-10-23 and 2026-11-07). Matters for Laravel/Symfony builds, not
for tests.

## Measurement (every epic)

- `MANTICORE_PHASE_TRACE=1` per phase; the 138-case sample timer
  (`.superpowers/sdd/2026-11-09-shared-generics/ctime.sh`, to be promoted to
  `tools/`), cold and warm.
- IR bytes: `tests/ir_budget`.
- Runtime: `bench/run.sh`, median of ≥ 15, A/B per change; layout artifacts only
  when proven by a link-order swap.
- Correctness: full AOT suite + Linux gate (run by the user).

## Expected ceiling

Trivial program: 1.1 s today → 0.1–0.3 s with E1 (warm/seeded) + E2 + E3 + E4:
only the user's code is lowered and compiled, everything else is reused.
