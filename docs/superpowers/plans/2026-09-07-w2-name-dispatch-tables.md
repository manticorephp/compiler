# W2 — dispatch by DATA, not by code

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** stop emitting a name-comparison CHAIN per dynamic call site. One table per candidate
set, one lookup, one indirect call.

**Architecture:** every dynamic-name dispatcher today expands to `N` blocks of
`strcmp` + `br` + a call, where `N` is every arity-compatible candidate in the program. Replace
the chain with (a) a per-candidate **thunk** with one uniform signature `i64 (i64, …)`, (b) a
per-shape **constant table** of `{ptr name, ptr thunk}`, and (c) a generic runtime lookup. Code
per site goes from `O(N)` blocks to `O(1)`; the `O(N)` part becomes constant DATA, which is
~4× cheaper per row in text and free at `-O2` (clang does not optimise a constant array).

**Spec:** [`docs/design/backend-and-build-strategy.md`](../../design/backend-and-build-strategy.md) §4 W2.

## Global Constraints

- **A dirty candidate keeps its inline arm.** By-ref parameters bind the CALLER's slot; a thunk
  taking `i64` cannot. The split is clean/dirty exactly as `emitDynMethodCall` already splits it.
- **Arguments are evaluated ONCE, at the site.** That is already the semantics (one arm runs);
  the table path makes it structural.
- **No default-argument invention.** First cut: a candidate whose declared arity differs from
  the site's argc stays dirty. Defaults are `emitCall`'s job and it is not in the thunk.
- **Cell boxing is the caller's job** and stays at the site (`boxToCell`), so one thunk serves
  every site with the same argc — see the "denormal doubles" comment in
  `EmitLlvmCalls::emitDynFnCall`, which is what a raw scalar into a cell param produced.
- A `linkonce_odr` body coalesces BY NAME: a thunk's symbol must encode everything it depends
  on (callee, argc, the site's arg kinds), or two modules will merge two different bodies.

## Measured baseline (2026-09-07, `38effef`, t1 corpus)

Loop, **24 s end to end**:

```bash
php tools/audit/gen_manifest.php 1 --app ~/var/projects/symfony-demo-probe/app \
    --out ~/var/projects/symfony-demo-probe/app/manticore.t1.json
cd ~/var/projects/symfony-demo-probe/app && \
    ~/var/projects/manticore-nameid/bin/manticore build --keep-ir manticore.t1.json
php tools/prof/ircensus.php ~/var/projects/symfony-demo-probe/app/audit-t1_bin.dbg.ll --top 20
```

- module **14.88 MB**, 13.66 MB inside `define`, 3215 functions.
- **4832 `strcmp` call sites.**
- The dispatchers, by name — every one of them a name chain over the whole program:

| symbol | MB |
|---|---|
| `__mc_call_shutdown_function` (+ `_array`) | 0.61 |
| `Fiber__mcRun` | 0.43 |
| `Http_Server__runHandler` | 0.42 |
| `__mc_call_exception_handler` | 0.31 |
| `__mc_dyn_spread_fallback` | 0.27 |
| `__mc_ob_call` | 0.24 |
| `__mir_export_object` (+ arms) | 0.22+ |
| `pcntl_signal_dispatch` | 0.19 |

≈ **2.7 MB of 14.88 MB — 18% of a tier-1 module** in eight functions, none of which is user
code. T5 shows the same shape at scale: `dynm 197 + dynf 81 + newdyn 36.5 = 315 MB` of 1.20 GB.
`dynm` does not appear at t1 (no `$obj->$name()` sites reach it there), so **t1 measures `dynf`
and t2/T5 measure `dynm`** — do not conclude from one corpus.

## What already exists (do not rebuild it)

- `EmitLlvmObjects::dynmChainFn` — one out-lined chain per SHAPE for dynamic METHOD calls. Its
  body is still a `strcmp` chain; it is the second target here.
- `EmitLlvmRuntime::dynamicMethodTableForClass` — a per-class `{ptr name, ptr tramp}` table
  plus `__mc_dyn_method_lookup` / `_has` / `_try_call` in `RuntimeLibrary`. **This is the
  pattern to copy**, including the null row for "declared but unsupported".
- `TrampolineSynth` — uniform-ABI thunks for METHODS. Functions have no equivalent yet.

---

### Task 1: `__mc_dynf_lookup` — the generic table probe

**Files:** Modify `src/Compile/Mir/RuntimeLibrary.php` (next to `__mc_dyn_method_lookup`)

**Interfaces:**
- Produces: `ptr @__mc_dynf_lookup(ptr %name, ptr %rows, i64 %n)` — linear scan, `strcmp`,
  first match wins (the chain's own order and semantics), `null` on a miss.

- [ ] **Step 1:** write a case `tests/aot/cases/dynf_table_order.php` that defines two functions
      whose names share a prefix, calls both through a runtime string, and prints the results —
      it must pass BEFORE the change (the chain already does this) and after.
- [ ] **Step 2:** `bash tests/aot/run.sh -k dynf_table_order` → PASS on the unmodified tree.
- [ ] **Step 3:** emit the lookup, gated behind the same `needs*` flag style the file uses.
- [ ] **Step 4:** re-run the case; `bash tests/aot/run.sh -k dyn -O 1` stays green.
- [ ] **Step 5:** commit.

### Task 2: the per-candidate thunk

**Files:** Modify `src/Compile/Mir/Passes/EmitLlvmCalls.php`

**Interfaces:**
- Produces: `dynfThunk(string $fname, array $argTypes): string` — the symbol of
  `define linkonce_odr i64 @manticore___mc_dynft_<mangled fname>_<argc>_<argkinds>(i64 %a0, …)`,
  or `''` when the candidate is dirty (any by-ref param, declared arity ≠ argc, a float/pointer
  pairing `dynArmTypesEmittable` already rejects).
- The body coerces each `%ai` from the site's arg kind to the parameter's carrier exactly as the
  spread path in `emitDynFnCall` does today, calls `@manticore_<fname>`, and `boxToCell`s the
  return.

- [ ] **Step 1:** a case per carrier — `int`, `float`, `string`, `array`, `cell` — through a
      runtime function name, checked against `php`. Write them first; they pass on the chain.
- [ ] **Step 2:** implement `dynfThunk`, emitted into the same extra-bodies buffer
      `dynmExtraBodies` uses.
- [ ] **Step 3:** `php tools/check_ir_arity.php <t1 .ll>` — **per file** — must stay clean; a
      thunk with the wrong argc reads garbage registers and clang says nothing.
- [ ] **Step 4:** commit.

### Task 3: the site — table + lookup + indirect call, dirty arms after it

**Files:** Modify `src/Compile/Mir/Passes/EmitLlvmCalls.php::emitDynFnCall`

- [ ] **Step 1:** split candidates into clean (a thunk exists) and dirty.
- [ ] **Step 2:** emit one `linkonce_odr constant` row array per shape, keyed like
      `dynmChainFn` keys its shape (candidate names + return kinds + arg kinds), so two sites
      with the same shape share one table.
- [ ] **Step 3:** at the site: evaluate args once → `__mc_dynf_lookup` → `icmp ne ptr null` →
      indirect `call i64 %fn(...)` on hit; on a miss fall into the existing dirty chain and then
      the existing miss behaviour (`store 0`).
- [ ] **Step 4:** `bash tests/aot/run.sh -k dyn -O 1`, then the full suite at the default level.
- [ ] **Step 5:** measure — rebuild t1, census, record the delta in this file.
- [ ] **Step 6:** commit.

### Task 4: the same treatment inside `dynmChainFn`

**Files:** Modify `src/Compile/Mir/Passes/EmitLlvmObjects.php`

- [ ] **Step 1:** the arms already call per-name helpers with ONE signature — so the table is
      `{ptr name, ptr helper}` and no new thunk is needed. Replace the chain body with the probe.
- [ ] **Step 2:** measure on t2/T5, not t1 (t1 has no `dynm`).
- [ ] **Step 3:** full suite + difftest. **Step 4:** commit.

### Task 5: gate

- [ ] `bash tests/aot/run.sh -j 0` · `bash tools/difftest.sh` · a self-build that is
      byte-identical across two generations · then the Linux gate (now nightly CI).
- [ ] Record the T5 number: IR bytes, peak RSS, wall.

## Exit criteria

- t1 `strcmp` sites well under 4832 and the eight dispatchers under 1 MB combined.
- T5 `dynf` under 20 MB (from 81), `dynm` under 40 MB (from 197), IR under 1.0 GB.
- Suite and difftest unchanged; two generations byte-identical.

## RESULT — Tasks 1-3 landed (`912b440`, branch `nameid`)

`emitDynFnCall` now splits its candidates: a callee with this site's exact arity and no
by-reference parameter is reached through a per-module `{ ptr name, ptr thunk }` table and
one `__mc_dynf_lookup`; everything else keeps its inline arm, and **the arms come first**, so
an argument expression is still evaluated exactly once (a firing arm evaluates it; the table
path is reached only when none fired).

**The ABI is CELLS and that is the whole trick.** The first cut keyed the thunk on the SITE's
argument types: 1510 thunks on t1 and a module that did not move (14.88 -> 14.85 MB) — the
bodies cost what the blocks they replaced cost. Boxing every argument to a cell at the site
makes the thunk depend on the callee and argc ONLY, so one body serves every site: 520 thunks,
and the carrier filter (`dynArmTypesEmittable`) stops being needed at all.

| corpus | IR bytes | `strcmp` sites | build wall |
|---|---|---|---|
| t1 before | 15 598 962 | 4 832 | 24 s |
| t1 after | 15 108 241 (**-3.2%**) | 3 323 (**-31%**) | 23 s |
| t2 before | 241 514 618 | 113 858 | 593 s |
| **t2 after** | **211 680 756 (-12.3%)** | **81 302 (-29%)** | **411 s (-31%)** |

Where it went on t2: `compiled php` 194.70 -> 165.69 MB; three closures that each carried a
whole-program chain, 7.98 -> 3.37 MB apiece. Cost: 805 thunks + 139 tables = +0.5 MB of
`runtime helper`.

⚠ **The t1 BINARY grew 2%** (3 771 008 -> 3 850 880) while its IR shrank. A thunk is
address-taken, so nothing downstream can fold it away the way a chain arm could be folded
into its site. IR volume and binary size are not the same objective — the wall clock is what
W2 is for, and on t2 that is -31%.

Verified: `tests/aot/cases/dynf_table.php` (every argument carrier, plus a `bump()` witness
that the arguments run once) matches `php`; 231 cases across `dyn`/`callable`/`closure`/
`func`/`call`/`str` green, **under gen2** — the generation whose own IR the new emitter
produced. ⛔ Full suite, difftest and Linux NOT run yet.

**Still on the table (next):** the SPREAD path is excluded (`$hasSpread` keeps the chain), and
that is where t1's remaining mass sits — `Fiber__mcRun` 0.43 MB, `__mc_call_shutdown_function`
0.43, `__mc_dyn_spread_fallback` 0.27, `__mc_call_shutdown_array` 0.18, all unchanged. A
spread site needs an args-VECTOR thunk (`i64 f(ptr %args)`), which is the shape
`__mc_dyn_method_try_call` already uses for methods. Task 4 (`dynmChainFn`) is untouched.

## RESULT 2 — the spread path and the method shape (`0023ef1`, `3d0f3f9`, `a44a0e9`)

**Spread sites now table too.** A spread arm never re-emitted the argument nodes to begin
with (it builds the call from the hoisted fixed values plus `__mir_array_value_at` reads), so
it converts cleanly: `i64 (ptr %spread, i64 %a0…)`, keyed on callee + prefix length + the
pack's ELEMENT type, fixed prefix boxed to cells at the site.

| corpus | IR | `strcmp` | wall |
|---|---|---|---|
| t1 baseline | 15 598 962 | 4 832 | 24 s |
| t1 now | **14 793 855 (-5.2%)** | **1 815 (-62%)** | 21 s |
| t2 baseline | 241 514 618 | 113 858 | 593 s |
| t2 now | **209 739 963 (-13.1%)** | **74 115 (-35%)** | **385-414 s (-30%)** |

`Fiber__mcRun` (0.43 MB) and `__mc_call_shutdown_function` (0.43 MB) are gone from t1's
fattest list entirely.

★★★ **A base-`Node` field read SIGSEGVs the native self-build.** `$iv->args[$i]->operand`
resolved by the wrong offset and killed the t2 build (`EXC_BAD_ACCESS` at 0x10, one frame
deep in `dynfSpreadThunk`); `asSpreadNode()` narrowing is the fix, and it is the same trap the
`DynProp_` comment in `emitInvoke` already names. t1 never reproduced it — **a corpus that
does not crash proves nothing about a bigger one**.

**The method side (Task 4) is in and is nearly free at t2**: one shape body in the whole tier
(209.885 -> 209.740 MB). Its family is a T5 shape — `dynm` was 197 MB of 1.20 GB there — so
the number that matters for it has not been taken yet.

⛔ Still not run: full suite, difftest, self-host fixpoint, Linux, T5.

## GATE (2026-09-08, branch `nameid`, gen2 + stdlib rebuilt by the new compiler)

- `tests/aot/run.sh -j 0` -> **passed 1061, failed 0, total 1063** (2 carry no expected output), 303 s.
- `tools/difftest.sh` -> **MATCH 978 - DIFF 2 - COMPILE 0 - TIMEOUT 0**, 1046 s.
  Both DIFFs (`error_handler_basic.php`, `trigger_deprecation_shape.php`) are **pre-existing on
  main** - verified by compiling each with the unmodified `bin/manticore` from the main
  checkout, and the nameid binary produces output BYTE-IDENTICAL to main for both. The
  divergence is in the error-message shape (a duplicated notice line plus an absolute vs
  relative path), not in dispatch.
- The stdlib was rebuilt by the new compiler first (`build --libs-only`), so this is not a
  new-compiler / old-stdlib hybrid.

⛔ Still not run: self-host fixpoint, Linux, T5.

## Risks, named

1. **A by-ref candidate silently going clean.** `anyRefParam` is the only guard; a miss writes
   into a temporary and the caller's variable never changes — a WRONG ANSWER, not a crash.
2. **Cell boxing at the wrong end.** The site boxes; the thunk must not box again. The existing
   "denormal doubles" bug is exactly this mistake in the other direction.
3. **`linkonce_odr` coalescing by name.** The thunk symbol must carry the arg kinds, or a second
   module with the same callee and a different site shape merges into it.
4. **A table row for a function the module does not define.** The chain skipped those by
   construction (`$this->sigs->returnType` is the local world); a table must too, or the link
   fails with an undefined symbol.
