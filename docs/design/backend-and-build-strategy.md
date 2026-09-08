# Backend & build strategy — the 2026-09 direction

_Written 2026-09-07 on `7b8a02f`, after the T5/T6 memory + IR-volume epics._

Answers one question: **now that self-hosting is the floor and real applications are the
target, where does the next engineering year go?** In particular — do we write our own
arm64/amd64 backend?

## 1. Diagnosis — LLVM is not the thing fighting us

Every root cause closed in the last two months lived on OUR side of the boundary:

| Epic | Root | Layer |
|---|---|---|
| array element release flavor | an element had no release flavor | MIR ownership |
| variadic pack | a pack element's release ≠ what its reference took | MIR ownership |
| `foreach` value is a borrow | the pass owned per NAME, the emitter decided per SITE | MIR ownership |
| rc flavor disagreement | one local name, two types, one flavor (first-write-wins) | MIR ownership |
| dynamic property bag | the bag is granted from the store, not from the layout | ABI |
| `sjlj` locals revert | `longjmp` restored pre-`try` values of promoted slots | codegen contract |
| IR volume 98× | three dispatchers SPLICED per call site | emission strategy |
| element repr / `cell` claim | a static claim with no runtime guarantee | type system |

LLVM appears exactly twice, and both times as a *messenger*: `clang: ran out of source
locations` on a 2.6 GB `.ll`, and a link peak ≈ 15× the `.ll`. Both were caused by how much
IR we emit. When we out-lined the spliced dispatchers, IR fell 36% and the wall fell with it.

**Conclusion:** the impression that we bend LLVM around PHP is inverted. What we actually pay
for is that **PHP has no static value representation and we committed only halfway** — half
the pipeline believes in typed slots, half believes in erasure, and every stitch between them
(`array` element, `$GLOBALS`, unhinted static prop, by-ref capture, `clone`, `foreach` value)
is a place where the two disagree. That seam is a middle-end problem. A new backend does not
touch it.

## 2. Verdict on an own backend: NO, not now

What it buys:

- a fast `-O0`-class codegen path for the dev loop. Real: today `clang` is ~72% serial of a
  build, T5 is 29m38.

What it costs:

- instruction selection + register allocation + an ABI implementation ×4 (SysV amd64,
  AAPCS64, and the Darwin variants of both), relocations, an ELF **and** Mach-O object
  writer, unwind tables (`.eh_frame` / `__unwind_info`), debug info.
- **the loss of `-O2`.** Our 2–50× over Zend on compute is largely LLVM's inliner, GVN and
  LICM. To hold the number we would have to write our own middle-end optimiser — i.e. build
  the part of LLVM that is actually earning its keep.
- it closes **zero** rows of "SYSTEMIC GAPS". Cell soundness, erased channels, references,
  element repr — all upstream of the backend.

So an own backend would replace the healthiest component in the system and leave every open
correctness gap exactly where it is.

**Re-entry criteria** (revisit only when ALL hold):

1. §4 W2 and W3 are done and `clang` still dominates the wall on a warm incremental build.
2. We want the dev loop under 10 s for a large target, and the split/`-O1` path is measurably
   at its floor.
3. Scope is a **dev-only baseline backend** (no optimiser, no LTO, straight-line selection
   from MIR, one ABI at a time) with LLVM retained for every shipped artifact — never a
   replacement.

Transpiling to C or C++ is a strictly worse LLVM: a slower front end, no control over the
ABI, and the same `clang` at the end. Not on the table.

## 3. Fast-run policy — optimisation levels (DECIDED)

`Compile\...\CompileArgs::$optLevel` defaults to `'2'` and `-O<level>` already accepts
`0 1 2 3 s z`. The `-j<n>` split is opt-in and defaults to 1.

**Policy:**

| Path | Flags | Why |
|---|---|---|
| Shipped artifact, `lib/*.o`, anything whose own speed matters | `-O2`, no split | a part boundary is an inlining boundary; the compiler built as 8 parts runs 43% slower, which then slows every later build |
| `bin/build` producing the installed `bin/manticore` | `-O2` + ThinLTO on the link | the compiler's speed compounds into every future build |
| **Iteration loop** — compile a program to run once, a filtered `tests/aot` run, an IR-volume A/B | **`-O1 -j0`, no LTO** | `clang -O2` is the single largest term; `-O1` keeps mem2reg and always-inline and drops the GVN/inliner cost that dominates our IR |
| A binary `lldb` must walk | `-O0 --keep-ir` | readable codegen, no reordering |

Prefer **`-O1` over `-O0`** for the iteration loop: `-O0` bloats the object, links slower, and
produces a binary so slow that a suite run costs back what the compile saved. `-O0` is for a
debugger, not for throughput.

⚠ **A green `-O1`/`-O0` run is NOT evidence about the shipped `-O2` binary.** The `sjlj`
locals bug was correct at `-O0` and WRONG at `-O2` — promoted slots restored to their pre-`try`
value. Fast loop finds bugs; only an `-O2` gate clears them.

**Measured 2026-09-07 on `7b8a02f`, macOS arm64** — the policy cites numbers, not taste:

| Measurement | `-O0` | `-O1` | `-O2` |
|---|---|---|---|
| compiler self-build (`build --apps-only`) | — | **71 s** | 83 s |
| the produced compiler's own front-end work (`analyze src`, 3×) | — | 0.937 s | 0.909 s |
| one bench-case compile (`json_records`) | 0.357 s | 0.505 s | 0.552 s |
| that case's runtime | 1.054 s | 0.703 s | 0.710 s |

`-O1` takes **14% off the build wall** and costs **~3%** on the produced compiler. `-O0`
compiles fastest and produces a binary ~50% slower — which is why it is a debugger
setting, not an iteration setting.

Shipped (2026-09-07, branch `ci`):

- **`bin/build --fast`** — `-O1`, application only, to `bin/manticore.fast`. Refuses to
  write `bin/manticore`, refuses `--verify`, never rebuilds `lib/*.o`. 71 s vs 83 s.
- **`tests/aot/run.sh -O <level>`** (or `MC_OPT`) — forwarded to every case compile,
  inherited by the parallel workers, printed in the summary line as `[-O<level>]`.
- Still open: `-j0`/no-LTO on the fast path — the split costs the produced program 43%,
  so it belongs to a throwaway binary only, and `--fast` does not pass it yet.

⚠ **Where `-O` does NOT pay: the case suite.** 153 cases, `-j 0`: 49 s at `-O2`, 48 s at
`-O1`. A test case is small enough that the front end and the link dominate, so the ~9%
off a single case compile disappears into the noise. The lever is the BIG target — the
self-build (−14%) and T5 — not the suite. Use `-O` on the suite for debugging codegen,
not for throughput.

## 4. The ladder — ordered by return per hour

### W0 — the fast loop (§3). Days.
Exit: a filtered `tests/aot` run and a single-program compile measurably faster; every runner
prints its opt level.

### W1 — CI. Days. **Highest return per hour in the list.** ✅ built 2026-09-07 (branch `ci`)

`tools/docker/gate.sh` is now the ONE definition of a Linux gate — `tools/docker/run_tests.sh`
and both workflows call it, so a CI green and a local green mean the same thing.
`.github/workflows/ci.yml` runs the cold seed + full suite on arm64 and amd64 per push;
`nightly.yml` runs the heavy gate (+ difftest + fixpoint) plus a macOS suite, and writes the
commit into the run summary. ⛔ Not yet exercised on GitHub — nothing is pushed.
There is none today, which is why the status file is full of "⛔ not run". A nightly
`tools/docker/run_tests.sh --gate` on arm64 **and** amd64 plus `tests/aot` + `difftest` on
macOS would have caught the `RC_ELEM_READ_OWNS` Linux miscompile weeks earlier, and would end
the "which commit is this green result from?" problem outright.
Exit: a nightly run whose result is attached to a commit hash, and a red one is a mail, not a
discovery three weeks later.

### W2 — kill IR volume with DATA, not code. 1–2 weeks. Bounded, measurable.
Measured on `7b8a02f`: 516 539 `strcmp` sites; `dynm 197 + dynf 81 + newdyn 36.5 = 315 MB` of
a 1.20 GB `.ll` — 26% of t2. The out-lining lever is spent; the remaining shape is a
comparison CHAIN emitted as code.

- Intern every method / function / class name at compile time to an i64 id; emit a per-module
  name table.
- Dispatch becomes a lookup over ids (perfect hash or binary search), not a `strcmp` chain.
  The erased entry hashes the string once, then compares ints.
- `dynf` is the hard one and the big one: its arm re-emits argument EXPRESSIONS through
  `emitCall` (by-ref), so out-lining it needs a calling convention, not a copy.

Loop: `php tools/prof/ircensus.php` for bytes-per-`define` by family;
`build --keep-ir manticore.t1.json` is a 23 s A/B.
Exit: the three families under 50 MB, T5 IR under 1.0 GB, and the clang wall down with it
(it tracks IR bytes near-linearly).

### W3 — incremental build / module `.o` cache. 2–3 weeks. Biggest velocity win.
Every build today is whole-program. `.sig` v2 and the module system already give us the
inputs; `MANTICORE_HOME` / `~/.manticore/cache` are designed in `design/module-system.md` and
absent from `src/`.

- Cache key: source hash ⊕ the `.sig` hashes of its dependencies ⊕ `MemoryAbi::VERSION` ⊕
  opt level ⊕ emitter flags. Any of those moving is a miss — emitter flags in the key is not
  optional, an emitter change is only under test one generation later.
- Fold in the emission-memory work: write IR per function and release it instead of holding a
  whole module string; drop AST per module after MIR. Emission owns the peak (T5 5.37 GiB).

Exit: touch one file in T5 and rebuild in minutes, not half an hour; peak RSS under 4 GiB.

### W4 — self-describing value channels + a MIR verifier. The epic. 1–2 months.
One root under most of the open gap list. `cell` is a static CLAIM with no runtime guarantee;
`plausiblePtrIr` DEREFERENCES an unvalidated word at ~10 sites.

1. Enumerate the erased PRODUCERS: `$GLOBALS['x']`, an unhinted static-prop read, an erased
   array element, a by-ref-captured local, `clone`, the `foreach` value, a homogeneous literal
   handed to an `array<K,mixed>` param. (Repros exist — the 12-liner in the `eidx` notes, and
   `tools/prof/foreach_borrow_uaf.php`.)
2. One canonical tagged word at every one of those boundaries. Bumps `MemoryAbi::VERSION`
   ⇒ one `bin/build --seed`.
3. **A verifier pass that FAILS THE BUILD** where a `cell`-typed edge is fed by a producer
   that cannot prove the claim. Harden `MANTICORE_TYPECHECK=1` into this and turn it on by
   default. A static claim nobody checks is how we got here.
4. Convert the `plausiblePtrIr` sites into assertions once the claim is guaranteed.

Unlocks: tagged arith (held back program-wide today), the `json_encode`-of-object class of
bugs, `FETCH_OBJ`/`FETCH_CLASS` in PDO, and it removes the *reason* per-site splicing existed
(feeds back into W2).
Exit: the repro set green, tagged arith on, `TypeCheck` on by default.

### W5 — strict containers as an OPT-IN mode. After W4.
We already carry the annotations (`@var array<int,string>`, `@template`); what is missing is
permission to *rely* on them. With W4's verifier able to prove a claim, an annotated container
can lower to a monomorphic vector/map with no element hint nibble, no erased index-get, no
tagged element read. That is typephp's speed profile on the paths where the user opted in,
without giving up the general path or the Zend oracle anywhere else.
Exit: an annotated hot loop measurably at native-container cost, difftest unchanged.

### Cheap items, any time
- `json_encode()` of any object returns `false` — a blocker for every real API. Covered by
  the per-class function-pointer epic already in Tier 3.
- Value-range analysis for int overflow → float (Tier 1 row 1).
- `-O1` vs `-O2` A/B on `bin/build` itself, now that `-flto=thin` on the link recovers split
  cost.

## 5. typephp (swoole), for calibration

AOT PHP → **C++17** → native, self-hosting, GPL-3.0. Strongly-typed containers
(`std::vector`/`std::map`) replace PHP arrays (their "10× array"), native scalars, strict
types always. No `eval`, no executable statements in global scope; dynamic operations fall
back to a **PHPX/Zend runtime**, i.e. Zend is still in the picture. ~8× on `bench.php`.

They avoid exactly the costs we have been paying — erasure, references, dynamic properties —
by declaring them unsupported. Different product, different contract. Our north star is Zend
semantics in a static binary with no Zend.

What to take: **W5** — an opt-in strict mode. What not to take: C++ as a target.

## 6. Sequencing

```
W1 (CI) ─┬─ W0 (fast loop)
         ├─ W2 (name ids / IR volume)  ──┐
         ├─ W3 (incremental + streaming) ─┴─> W4 (channels + verifier) ──> W5 (strict mode)
```

W0/W1 first because everything after them is measured, and today nothing is measured on a
schedule. W2 and W3 are independent and both bounded. W4 is the only open-ended one, and it
is the one that pays in correctness rather than seconds.
