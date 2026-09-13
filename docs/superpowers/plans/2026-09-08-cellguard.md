# cellguard (W4) Implementation Plan

> ## Status at merge (2026-09-13) — read this before the body below
>
> The body of this document is the design/plan AS WRITTEN. What shipped differs;
> the tracked record of the shipped instrument is **`docs/design/cellguard.md`**.
>
> **Shipped:** Tasks 1–6 (provenance map, boxed/opaque marking, the six sink
> checks, the summary line, `tools/cellguard_scan.sh`, `MANTICORE_CELL_ASSERT`),
> 5b (array-element reads marked `opaque` on the array's declared element type —
> they were `raw` by omission, 4371 of the first census's 5784 "violations"),
> 5c (a Monomorphize clone's return checked against the clone's own type, not
> the generic's; ~10 builtins that box by construction marked), and Task 9
> **reframed**: not "fatal on any violation" but a RATCHET — a committed baseline
> (`tools/cellguard_baseline.txt`) of known sites; `--ratchet` fails only on a
> NEW site; `--update-baseline` rewrites it explicitly, never automatically.
>
> **Reframed:** Task 9's exit criterion "violations: 0" is unreachable by closing
> producers in census order — the head of the census IS the tagged-arithmetic
> gate this epic was meant to open. The gate stays `false &&`.
>
> **Deferred to the element-channel epic:** Task 7 (remaining producers), Task 8
> (turn tagged arithmetic on), Task 10 (`plausiblePtrIr` → assertions).
>
> **Corrected lattice** (the body's table is stale): `emitCellifyArrayRaw` is
> `opaque` (it returns a raw array POINTER whose elements are cells), not
> `boxed`; there is NO phi step — a phi is `raw` unless later marked; a fourth
> provenance **`probed`** exists for the two runtime bit-pattern probes
> (`__mir_box_unknown`, `boxUnknownIfRaw`): counted, never a violation, never
> trusted as boxed. The site key is `(sink, fn, ord)`, not `(fn, line)`.
>
> **Known blind spots:** (1) the `$GLOBALS` two-view slot — `emitLoadLocal`
> returns at `:401`/`:411` before the `:452` mark, so neither instrument sees a
> `globalBacked` local; (2) ~4362 `opaque` element reads with no runtime
> corroboration; (3) 192 corpus cases (187 pre-merge) uncompilable under the Zend host
> (rc=70, unknown status, never counted clean); (4) **floats are stored
> UNTAGGED** — every legitimate float in a `mixed` slot fires `CELLASSERT`; the
> assert cannot tell a raw word from a double, only the `CELLASSERTSITE` table's
> static kind/slot/decl can, post hoc.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Type::KIND_CELL` a runtime guarantee instead of a static claim, so tagged arithmetic can be switched on and `plausiblePtrIr` stops dereferencing unvalidated words.

**Architecture:** An emitter-seam verifier tracks the provenance (BOXED / OPAQUE / RAW) of every SSA register the LLVM emitter produces, and reports any RAW value stored into a `cell`-typed sink. A runtime tag assertion calibrates it. Only then are the producers it names fixed — in inference, never in codegen — and only then is the `false &&` gate at `InferTypes.php:1701` removed.

**Tech Stack:** Pure PHP compiler under `src/`, self-hosted; LLVM IR emitted as text by `EmitLlvm*.php` traits; tests are `tests/aot/cases/*.php` + `expected/*.out` output pairs.

**Spec:** `docs/superpowers/specs/2026-09-08-cellguard-design.md`

## Global Constraints

- Branch `cellguard`, worktree `~/var/projects/manticore-cellguard`, off LOCAL `main` `77a10e8`. Never push.
- **Fix erasure in inference, not in codegen.** An inference fix needs no producer/consumer co-flip, so the self-host fixpoint re-converges for free. Touch a codegen A-site only when no inference-side cause remains.
- **No consumer carve-outs.** Do not exempt one consumer channel at a time from tagged arithmetic; §18.3b records that this was written and deleted because it relocates the lie.
- **Never rebuild the compiler while a suite or difftest is running** — the runner invokes `bin/manticore` per case and would mix two compilers into one result.
- **Never run the full suite, difftest, fixpoint or the Linux gate unprompted.** Ask; the user runs heavy gates.
- Iterate on the ~3 s Zend loop (`tools/compile_user_mir.php`) before any `bin/build`.
- Shell is **fish**; there is **no perl** and **no python** — repo tools are PHP (`php tools/<x>.php`) or bash. `-j 0` is a separate argument to `tests/aot/run.sh`, not `-j0`.
- Cap every log. A failed Zend seed once wrote 66 GB into one file. Delete traces right after reading them.
- Emitter diagnostics use `\error_log(...)`, the idiom already used by `MANTICORE_DROP_TRACE` (`EmitLlvm.php:3941`).
- New emitter code goes in a trait `use`d by the `EmitLlvm` host (`EmitLlvm.php:118-131`), matching `EmitLlvmVisit` / `EmitLlvmMemory`.
- **Do not narrow a `Node` subtype inside a trait** — it fails there; go through `Walk::children`.
- A `MemoryAbi::VERSION` bump forces `bin/build --seed` (~8 min). Tasks 1-6 must not bump it.

---

### Task 1: Provenance map + BOXED marking at the five boxers

**Files:**
- Create: `src/Compile/Mir/Passes/EmitLlvmCellGuard.php`
- Modify: `src/Compile/Mir/Passes/EmitLlvm.php:131` (add `use EmitLlvmCellGuard;`)
- Modify: `src/Compile/Mir/Passes/EmitLlvmBuiltins.php` — `boxToCell` (:796), `boxToCellShallow` (:566), `boxUnknownShallowIr` (:593), `boxRawValue` (:2578), `emitCellifyArrayRaw` (:1106)
- Modify: `src/Compile/Mir/Passes/EmitLlvmExpr.php:2016` — `boxLastByRepr`
- Test: `tools/cellguard_scan.sh` is created in Task 4; this task is verified by the probe below.

**Interfaces:**
- Produces: `markCellBoxed(string $reg): void`, `markCellOpaque(string $reg): void`, `cellProvenance(string $reg): string` returning one of `'boxed' | 'opaque' | 'raw'`, `resetCellGuardFrame(): void`, and `private array $cellProv` keyed by SSA register name (`%rN`).
- Consumes: `$this->lastValue` (`EmitLlvm.php:3133`), `SsaBuilder::allocReg()` (`SsaBuilder.php:28`).

- [ ] **Step 1: Create the trait with the map and its three accessors**

```php
<?php

namespace Compile\Mir\Passes;

/**
 * Provenance of an emitted SSA value with respect to the CELL contract.
 *
 * `cell` is a static claim; this trait is what turns it into something
 * checkable. Every register the emitter produces is classified:
 *   - `boxed`  — it came out of a boxing helper or a `@__manticore_box_*` call
 *   - `opaque` — it was loaded from a slot already typed `cell`, or returned
 *                by a callee whose signature says `cell`
 *   - `raw`    — anything else
 *
 * A `raw` value reaching a `cell`-typed sink is a proven contract violation.
 * An `opaque` one is not checkable here; the COUNT of them is the coverage
 * metric that says how much this instrument can see.
 *
 * A fourth bucket, `unchecked`, counts a sink whose DESTINATION SLOT TYPE could
 * not be determined statically — a dynamic-property store picks one of N slots
 * through a runtime strcmp chain, and some element/property stores are narrower
 * than the emitter's own box predicates. Never guess such a slot's type and
 * never skip it silently: count it, so the census states its own blind spots
 * instead of looking complete.
 */
trait EmitLlvmCellGuard
{
    /** @var array<string, string> SSA register name (`%rN`) → 'boxed'|'opaque' */
    private array $cellProv = [];

    /** @var array<string, int> provenance → count at a cell sink, for the summary line */
    private array $cellGuardCounts = ['boxed' => 0, 'opaque' => 0, 'raw' => 0, 'unchecked' => 0];

    /** @var string[] one human-readable line per RAW → cell violation */
    public array $cellGuardViolations = [];

    private function cellGuardOn(): bool
    {
        return \getenv('MANTICORE_CELLGUARD') !== false;
    }

    private function markCellBoxed(string $reg): void
    {
        if ($reg !== '') { $this->cellProv[$reg] = 'boxed'; }
    }

    private function markCellOpaque(string $reg): void
    {
        if ($reg !== '' && !isset($this->cellProv[$reg])) {
            $this->cellProv[$reg] = 'opaque';
        }
    }

    private function cellProvenance(string $reg): string
    {
        return $this->cellProv[$reg] ?? 'raw';
    }

    /** Registers are numbered per function, so the map must not outlive one. */
    private function resetCellGuardFrame(): void
    {
        $this->cellProv = [];
    }
}
```

- [ ] **Step 2: Wire the trait into the emitter host**

In `src/Compile/Mir/Passes/EmitLlvm.php`, after line 131 (`use EmitLlvmFiber;`), add:

```php
    use EmitLlvmCellGuard;
```

- [ ] **Step 3: Mark BOXED at every boxing helper's exit**

Each of the six helpers ends by setting `$this->lastValue` to the boxed register (directly or through `finishI64`). Add, immediately before each `return` that hands back boxed IR:

```php
        $this->markCellBoxed($this->lastValue);
```

Apply to the arms that PERFORM a box: `boxToCell` (`EmitLlvmBuiltins.php:796`), `boxToCellShallow` (:566), `boxUnknownShallowIr` (:593), `boxRawValue` (:2578), `boxLastByRepr` (`EmitLlvmExpr.php:2016`).

**A pass-through arm must PROPAGATE, not assert.** `boxToCell`'s `KIND_CELL` arm and `boxRawValue`'s `KIND_CELL` arm emit no box call — they hand back a value whose static type already claims cell, which is the very claim under audit. Asserting `boxed` there overwrites a RAW input with BOXED and erases the violation class this epic exists to find. Add and use:

```php
    /** A pass-through transmits provenance; it does not create it. */
    private function propagateCellProvenance(string $from, string $to): void
    {
        if ($from === '' || $to === '' || $from === $to) { return; }
        if (isset($this->cellProv[$from])) { $this->cellProv[$to] = $this->cellProv[$from]; }
    }
```

`emitCellifyArrayRaw` (:1106) marks **`opaque`**, not `boxed`: it rebuilds the array with boxed elements, but the register it returns is a raw array POINTER, not a tagged word. Arrays ride raw in a cell channel by design, and an array in a `$GLOBALS` slot is the known-open channel Task 3 uses as its positive test — asserting `boxed` there would blind the instrument on the one case that proves it works.

- [ ] **Step 4: Reset the map per function**

Find where the emitter installs a new `FunctionEmitFrame` into `$this->frame` (`EmitLlvm.php:275` declares it). At that assignment add:

```php
        $this->resetCellGuardFrame();
```

Registers restart at `%r0` for each function, so a map that outlives a frame reports one function's provenance against another's registers.

- [ ] **Step 5: Probe that the marks are being set**

```bash
cd ~/var/projects/manticore-cellguard
cat > /tmp/cg_probe.php <<'PHP'
<?php
function takesMixed(mixed $v): string { return \gettype($v); }
echo takesMixed(7), "\n";
PHP
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
  MANTICORE_PRELUDE=$PWD/prelude MANTICORE_CELLGUARD=1 \
  php -d memory_limit=2048M tools/compile_user_mir.php /tmp/cg_probe.php > /tmp/cg_probe.ll 2>/tmp/cg_probe.err
grep -c "call i64 @__manticore_box_int" /tmp/cg_probe.ll
```

Expected: a non-zero count — the int literal is boxed into the `mixed` parameter, so at least one BOXED mark had a register to attach to. The `.ll` must still be produced (the trait changes no IR).

- [ ] **Step 6: Commit**

```bash
git add src/Compile/Mir/Passes/EmitLlvmCellGuard.php src/Compile/Mir/Passes/EmitLlvm.php \
        src/Compile/Mir/Passes/EmitLlvmBuiltins.php src/Compile/Mir/Passes/EmitLlvmExpr.php
git commit -m "cellguard: provenance map, BOXED marked at the six boxing helpers"
```

---

### Task 2: OPAQUE marking, so the coverage metric is honest

**Files:**
- Modify: `src/Compile/Mir/Passes/EmitLlvmLocals.php` — `emitLoadLocal`
- Modify: `src/Compile/Mir/Passes/EmitLlvmObjects.php` — `emitPropertyAccess`, `emitStaticProp`
- Modify: `src/Compile/Mir/Passes/EmitLlvmCalls.php` — the call-return site
- Test: the probe in Step 4 below.

**Interfaces:**
- Consumes: `markCellOpaque(string $reg)` from Task 1.
- Produces: nothing new; it only populates the map.

Without this task every legitimately-cell value looks RAW and Task 3 reports a flood of false violations.

- [ ] **Step 1: Mark a load from a cell-typed slot as OPAQUE**

In `emitLoadLocal` (`EmitLlvmLocals.php`), after the load sets `$this->lastValue`:

```php
        if ($n->type->kind === Type::KIND_CELL) {
            $this->markCellOpaque($this->lastValue);
        }
```

Apply the same three lines in `emitPropertyAccess` and `emitStaticProp` (`EmitLlvmObjects.php`), keyed on the node's own `->type`.

- [ ] **Step 2: Mark a cell-typed call return as OPAQUE**

In `EmitLlvmCalls.php`, where the emitter assigns the call's result register to `$this->lastValue`:

```php
        if ($retType !== null && $retType->kind === Type::KIND_CELL) {
            $this->markCellOpaque($this->lastValue);
        }
```

Use whatever local already holds the callee's return `Type` at that point; do not re-resolve the signature.

- [x] **Step 3: RULED OUT — this codebase has no cell-carrying phi**

Investigated and withdrawn. The emitter writes 70 `phi` instructions, but every one of them
is inside a hand-written IR template in a builtin or runtime body, with fixed register names,
producing a raw scalar (a `strpos` index, a loop counter, a comparison result). None merges a
`cell`-typed user value.

User-level merges — a ternary, a null-coalesce, a loop-carried local — do not go through a phi
at all: the emitter stores to an alloca and reloads, and `Compile\Mir\HoistAllocas` plus LLVM's
own mem2reg promote them afterwards. So a loop-carried cell is already covered by Step 1's
`emitLoadLocal` marking, and marking a builtin's raw phi result `opaque` would be wrong — it
would hide a genuinely raw value behind the "not checkable" bucket.
- [ ] **Step 4: Probe that a cell round-trip is OPAQUE, not RAW**

```bash
cd ~/var/projects/manticore-cellguard
cat > /tmp/cg_opaque.php <<'PHP'
<?php
function pass(mixed $v): mixed { return $v; }
$x = pass(7);
echo \gettype($x), "\n";
PHP
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
  MANTICORE_PRELUDE=$PWD/prelude MANTICORE_CELLGUARD=1 \
  php -d memory_limit=2048M tools/compile_user_mir.php /tmp/cg_opaque.php > /tmp/cg_opaque.ll 2>&1
echo "exit=$?"
```

Expected: `exit=0` and a `.ll` produced. This step only proves the marking code runs without breaking emission; the counts are asserted in Task 4.

- [ ] **Step 5: Commit**

```bash
git add src/Compile/Mir/Passes/EmitLlvmLocals.php src/Compile/Mir/Passes/EmitLlvmObjects.php \
        src/Compile/Mir/Passes/EmitLlvmCalls.php
git commit -m "cellguard: OPAQUE provenance for cell loads and cell-typed call returns"
```

---

### Task 3: Sink checks on the five store kinds

**Files:**
- Modify: `src/Compile/Mir/Passes/EmitLlvmCellGuard.php` (add `checkCellSink`)
- Modify: `src/Compile/Mir/Passes/EmitLlvmVisit.php:145` (`visitStoreLocal`), `:283` (`visitStoreStaticProp`), `:441` (`visitStoreElement`), `:471` (`visitStoreDynProp`), `:476` (`visitStoreProperty`)
- Test: Task 4's scan script.

**Interfaces:**
- Consumes: `cellProvenance(string $reg)`, `$this->cellGuardViolations`, `$this->frame` (`FunctionEmitFrame::$name`), `Node::$line`.
- Produces: `checkCellSink(string $sinkKind, \Compile\Mir\Type $destType, \Compile\Mir\Node $site): void`.

`EmitLlvmVisit` is the single double-dispatch table for every node kind, so all five sinks are instrumented in one file with no change to the emit methods themselves.

- [ ] **Step 1: Add the sink check to the trait**

```php
    /**
     * One cell sink. `$destType` is the slot's declared type; the value being
     * stored is whatever the emit method left in `$this->lastValue`, which is
     * why every caller runs AFTER its emit method. `$site` is for the report.
     */
    private function checkCellSink(
        string $sinkKind,
        \Compile\Mir\Type $destType,
        \Compile\Mir\Node $site
    ): void {
        if (!$this->cellGuardOn()) { return; }
        if ($destType->kind !== \Compile\Mir\Type::KIND_CELL) { return; }
        $prov = $this->cellProvenance($this->lastValue);
        $this->cellGuardCounts[$prov] = ($this->cellGuardCounts[$prov] ?? 0) + 1;
        if ($prov !== 'raw') { return; }
        $fn = $this->frame !== null ? $this->frame->name : '(module)';
        $this->cellGuardViolations[] = 'CELLGUARD raw->cell ' . $sinkKind
            . ' fn=' . $fn
            . ' line=' . (string)$site->line
            . ' node=' . $site->kind;
        \error_log($this->cellGuardViolations[\count($this->cellGuardViolations) - 1]);
    }
```

- [ ] **Step 2: Call it from the five store visitors**

In `EmitLlvmVisit.php`, rewrite each of the five to check after the emit method has run (the emit method is what leaves the stored value in `$this->lastValue`):

```php
    public function visitStoreLocal(StoreLocal $n): string
    {
        $out = $this->emitStoreLocal($n);
        $this->checkCellSink('store_local', $n->type, $n);
        return $out;
    }
```

Repeat verbatim for `visitStoreStaticProp` (`'store_static_prop'`, `emitStoreStaticProp`), `visitStoreElement` (`'store_element'`, `emitStoreElement`), `visitStoreDynProp` (`'store_dyn_prop'`, `emitStoreDynProp`) and `visitStoreProperty` (`'store_property'`, `emitStoreProperty`).

If a store node carries the slot type somewhere other than `$n->type`, pass that field instead — do not invent a resolver in the visitor.

- [ ] **Step 3: Verify a known-good program reports nothing**

```bash
cd ~/var/projects/manticore-cellguard
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
  MANTICORE_PRELUDE=$PWD/prelude MANTICORE_CELLGUARD=1 \
  php -d memory_limit=2048M tools/compile_user_mir.php /tmp/cg_probe.php \
  > /tmp/cg_probe2.ll 2>/tmp/cg_probe2.err
grep -c CELLGUARD /tmp/cg_probe2.err
```

Expected: `0`. A boxed int into a `mixed` param is the contract honoured.

- [ ] **Step 4: Verify a known-BROKEN program reports a violation**

`$GLOBALS['x']` holding an array is an open channel recorded in `tests/aot/cases/globals_cell_repr.php`.

```bash
cd ~/var/projects/manticore-cellguard
cat > /tmp/cg_bad.php <<'PHP'
<?php
$g = [1, 2, 3];
$GLOBALS['g'] = [4, 5, 6];
\var_dump($GLOBALS['g']);
PHP
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
  MANTICORE_PRELUDE=$PWD/prelude MANTICORE_CELLGUARD=1 \
  php -d memory_limit=2048M tools/compile_user_mir.php /tmp/cg_bad.php \
  > /tmp/cg_bad.ll 2>/tmp/cg_bad.err
grep CELLGUARD /tmp/cg_bad.err | head
```

Expected: at least one `CELLGUARD raw->cell` line. If there is none, the instrument is blind — that is a Task 3 bug, not a "nothing to fix" result, and must be resolved before Task 4.

- [ ] **Step 5: Commit**

```bash
git add src/Compile/Mir/Passes/EmitLlvmCellGuard.php src/Compile/Mir/Passes/EmitLlvmVisit.php
git commit -m "cellguard: report raw->cell at the five store sinks"
```

---

### Task 4: Argument and return sinks, plus the summary line

**Files:**
- Modify: `src/Compile/Mir/Passes/EmitLlvmExpr.php:4710` (`unboxCellArg`) — the call-argument sink
- Modify: `src/Compile/Mir/Passes/EmitLlvmVisit.php` — `visitReturn`
- Modify: `src/Compile/Mir/Passes/EmitLlvmCellGuard.php` — add `cellGuardSummary()`
- Modify: `src/Compile/Mir/Passes/EmitLlvmModule.php` — emit the summary once per module

**Interfaces:**
- Consumes: `checkCellSink` from Task 3.
- Produces: `cellGuardSummary(): string` — `CELLGUARD summary boxed=N opaque=N raw=N`.

- [ ] **Step 1: Check the call-argument sink**

`unboxCellArg` (`EmitLlvmExpr.php:4710`) is where an argument meets its parameter type. After the argument value is emitted and the parameter type is known to be `cell`, call:

```php
        $this->checkCellSink('call_arg', $ptypes[$pi], $a);
```

- [ ] **Step 2: Check the return sink**

In `visitReturn` (`EmitLlvmVisit.php`), after the emit call, when the enclosing frame's `returnType` is a cell:

```php
        if ($this->frame !== null && $this->frame->returnType !== null) {
            $this->checkCellSink('return', $this->frame->returnType, $n);
        }
```

- [ ] **Step 3: Add the summary**

```php
    /** Coverage metric: a large `opaque` share means this instrument is blind. */
    private function cellGuardSummary(): string
    {
        return 'CELLGUARD summary'
            . ' boxed=' . (string)($this->cellGuardCounts['boxed'] ?? 0)
            . ' opaque=' . (string)($this->cellGuardCounts['opaque'] ?? 0)
            . ' raw=' . (string)($this->cellGuardCounts['raw'] ?? 0)
            . ' unchecked=' . (string)($this->cellGuardCounts['unchecked'] ?? 0)
            . ' violations=' . (string)\count($this->cellGuardViolations);
    }
```

Emit it with `\error_log($this->cellGuardSummary());` at the end of module emission in `EmitLlvmModule.php`, guarded by `if ($this->cellGuardOn())`.

- [ ] **Step 4: Verify the summary appears and the counts are non-degenerate**

```bash
cd ~/var/projects/manticore-cellguard
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig \
  MANTICORE_PRELUDE=$PWD/prelude MANTICORE_CELLGUARD=1 \
  php -d memory_limit=2048M tools/compile_user_mir.php /tmp/cg_bad.php \
  > /dev/null 2>/tmp/cg_sum.err
grep "CELLGUARD summary" /tmp/cg_sum.err
```

Expected: one summary line with `boxed` > 0. If `opaque` dominates `boxed` by more than roughly 10:1, stop and report it — the instrument cannot see enough to justify Task 6, and Task 2's marking needs widening first.

- [ ] **Step 5: Commit**

```bash
git add src/Compile/Mir/Passes/EmitLlvmExpr.php src/Compile/Mir/Passes/EmitLlvmVisit.php \
        src/Compile/Mir/Passes/EmitLlvmCellGuard.php src/Compile/Mir/Passes/EmitLlvmModule.php
git commit -m "cellguard: argument and return sinks, plus the coverage summary"
```

---

### Task 5: `tools/cellguard_scan.sh` — the regression guard

**Files:**
- Create: `tools/cellguard_scan.sh`
- Test: the script is the test.

**Interfaces:**
- Consumes: `MANTICORE_CELLGUARD=1` from Tasks 1-4.
- Produces: exit 0 when the corpus is clean and the known-broken case is still caught; exit 1 otherwise.

Modelled on `tools/typecheck_scan.sh`, which asserts the same two properties for the type checker.

- [ ] **Step 1: Write the script**

```bash
#!/usr/bin/env bash
# cellguard (MANTICORE_CELLGUARD=1) census + regression guard.
# Runs the emitter-seam cell verifier over the AOT corpus under the ZEND host,
# so no self-build is needed. Prints a ranked violation census and asserts the
# positive case still fires.
set -u
cd "$(dirname "$0")/.."

export MC_SRC="$PWD/src"
export MC_SIG="$PWD/lib/manticore_stdlib.o.sig"
export MANTICORE_PRELUDE="$PWD/prelude"
export MANTICORE_CELLGUARD=1

out=/tmp/cellguard_scan
rm -rf "$out"; mkdir -p "$out"
fail=0

echo "── corpus census ──"
for f in tests/aot/cases/*.php; do
    b=$(basename "$f" .php)
    php -d memory_limit=2048M tools/compile_user_mir.php "$f" \
        > /dev/null 2> "$out/$b.err" || true
    n=$(grep -c "CELLGUARD raw->cell" "$out/$b.err" || true)
    [ "$n" = "0" ] || echo "$n $b"
done | sort -rn | tee "$out/census.txt"

total=$(awk '{s+=$1} END {print s+0}' "$out/census.txt")
files=$(wc -l < "$out/census.txt" | tr -d ' ')
echo "violations: $total across $files case(s)"

echo "── by sink kind ──"
cat "$out"/*.err 2>/dev/null | grep -o "raw->cell [a-z_]*" | sort | uniq -c | sort -rn

echo "── positive: an array through a \$GLOBALS slot (expect a violation) ──"
cat > "$out/bad.php" <<'PHP'
<?php
$g = [1, 2, 3];
$GLOBALS['g'] = [4, 5, 6];
\var_dump($GLOBALS['g']);
PHP
php -d memory_limit=2048M tools/compile_user_mir.php "$out/bad.php" \
    > /dev/null 2> "$out/bad.err" || true
if grep -q "CELLGUARD raw->cell" "$out/bad.err"; then
    echo "ok: known-broken channel still caught"
else
    echo "FAIL: instrument went blind on the positive case"; fail=1
fi

[ "$fail" = "0" ] && echo "CELLGUARD SCAN OK" || { echo "CELLGUARD SCAN FAILED"; exit 1; }
```

- [ ] **Step 2: Make it executable and run it**

```bash
cd ~/var/projects/manticore-cellguard
chmod +x tools/cellguard_scan.sh
bash tools/cellguard_scan.sh 2>&1 | tail -40
```

Expected: `CELLGUARD SCAN OK`, a ranked census, and a per-sink-kind histogram. The census is the deliverable — it is the producer list Task 7 works from.

- [ ] **Step 3: Commit**

```bash
git add tools/cellguard_scan.sh
git commit -m "cellguard: corpus census and regression guard"
```

---

### Task 6: Runtime cross-check — `MANTICORE_CELL_ASSERT=1`

**Files:**
- Modify: `src/Compile/Mir/Passes/EmitLlvmRuntime.php` — define `__mir_assert_cell`
- Modify: `src/Compile/Mir/Passes/EmitLlvmCellGuard.php` — emit the call at cell reads
- Create: `docs/status/CELLGUARD-CENSUS-2026-09-08.md`

**Interfaces:**
- Consumes: the emitter's existing `needs*` runtime-feature flags (`RuntimeFeatures`), the pattern every other `__mir_*` helper already follows.
- Produces: `__mir_assert_cell(i64 %v, i64 %site)` — tag-tests `%v`, prints `CELLASSERT site=<n> word=<v>` to stderr on failure, returns; it never aborts.

`__mir_assert_cell` is emitted directly into the module by the runtime emitter, exactly like the other `__mir_*` helpers. It is not a PHP-visible function name, so the BOOTSTRAP RULE (a PHP body before a codegen builtin) does not apply and no prelude body is needed.

- [ ] **Step 1: Define the helper**

Add it beside the existing tagged-value helpers in `EmitLlvmRuntime.php`, gated by a new
`RuntimeFeatures` flag `needsCellAssert`. Emit this definition, using the same string-emission
helper the neighbouring `__mir_*` definitions in that file use:

```llvm
define void @__mir_assert_cell(i64 %v, i64 %site) {
entry:
  ; a boxed cell carries the NaN-box tag; a raw word does not
  %tagged = call i1 @__manticore_is_tagged(i64 %v)
  br i1 %tagged, label %ok, label %bad
bad:
  %fmt = getelementptr inbounds [34 x i8], ptr @.cellassert.fmt, i64 0, i64 0
  call i32 (ptr, ptr, ...) @fprintf(ptr @__mir_stderr, ptr %fmt, i64 %site, i64 %v)
  br label %ok
ok:
  ret void
}
```

with `@.cellassert.fmt` the private constant `"CELLASSERT site=%lld word=%lld\0A\00"`.
Reuse the module's existing tag predicate rather than open-coding the mask: `boxToCell`
(`EmitLlvmBuiltins.php:796`) names it. It prints and returns — it never aborts, because a
calibration run must reach the end of the suite.

- [ ] **Step 2: Emit the call at every cell read**

In `EmitLlvmCellGuard`, add:

```php
    private function cellAssertOn(): bool
    {
        return \getenv('MANTICORE_CELL_ASSERT') !== false;
    }

    /** Emit a non-fatal tag check at a cell READ. Returns IR text, or ''. */
    private function emitCellAssert(string $reg): string
    {
        if (!$this->cellAssertOn()) { return ''; }
        $this->rt->needsCellAssert = true;
        $site = \count($this->cellAssertSites);
        $this->cellAssertSites[] = ($this->frame !== null ? $this->frame->name : '(module)');
        return '  call void @__mir_assert_cell(i64 ' . $reg . ', i64 ' . (string)$site . ")\n";
    }
```

Add `/** @var string[] site id → enclosing function */ private array $cellAssertSites = [];` to the trait, and append `emitCellAssert()`'s result at the three SLOT-read sites Task 2 Step 1 marks OPAQUE: `emitLoadLocal`, `emitPropertyAccess` and `emitStaticProp`. A call return is not a slot read and a phi has no slot to assert against, so neither takes an assertion.

- [ ] **Step 3: Build and run the corpus under the assertion**

Ask the user before this step — it needs a full build.

```bash
cd ~/var/projects/manticore-cellguard
MANTICORE_CELL_ASSERT=1 bin/build --fast 2>&1 | tail -5
bash tests/aot/run.sh -j 0 2>&1 | tail -20
```

Collect every `CELLASSERT` line the suite prints.

- [ ] **Step 4: Diff the runtime site set against the static census**

Write `docs/status/CELLGUARD-CENSUS-2026-09-08.md` with three sections:
1. the static census from Task 5 (ranked cases, per-sink histogram, coverage summary);
2. the runtime `CELLASSERT` site set from Step 3;
3. the two-way diff — runtime-only sites are Stage 1 blind spots and get fixed in Tasks 1-4; static-only sites are cold paths or false positives, and a static rule is narrowed only with a witness.

- [ ] **Step 5: Commit**

```bash
git add src/Compile/Mir/Passes/EmitLlvmRuntime.php src/Compile/Mir/Passes/EmitLlvmCellGuard.php \
        docs/status/CELLGUARD-CENSUS-2026-09-08.md
git commit -m "cellguard: runtime tag assertion and the calibration census"
```

---

### CHECKPOINT — re-plan Task 7 from the census

Stop here and report the census to the user. The spec deliberately does not fix the producer list in prose: §18 of `unknown-cell-soundness.md` measured two of §17's three premises false, so a prose list has already been wrong once.

Expected candidates, to be **confirmed or killed** by the census, not assumed:
- an array held in a `$GLOBALS['x']` slot (scalars closed 2026-09-08, `tests/aot/cases/globals_cell_repr.php`);
- an array held in a static `mixed` property;
- the elements of an erased array (`array_combine` + `array_map` + `foreach`);
- an unhinted static-property read, typed `unknown` while the store boxes by declared type.

Instantiate one Task 7 per confirmed producer, ranked by violation count.

---

### Task 7 (template — one instance per confirmed producer): close producer `<NAME>`

**Files:**
- Modify: the inference pass that erases the channel — expected to be `src/Compile/Mir/Passes/InferTypes.php` or the relevant `Lower*.php`
- Create: `tests/aot/cases/cellguard_<name>.php`
- Create: `tests/aot/expected/cellguard_<name>.out`

**Interfaces:**
- Consumes: the census entry naming the sink kind, function and line.
- Produces: that census entry at zero.

- [ ] **Step 1: Write the failing case**

Create `tests/aot/cases/cellguard_<name>.php` reproducing the channel in the fewest lines that still fault, and generate the expectation from the oracle:

```bash
cd ~/var/projects/manticore-cellguard
php -d xdebug.mode=off tests/aot/cases/cellguard_<name>.php > tests/aot/expected/cellguard_<name>.out
cat tests/aot/expected/cellguard_<name>.out
```

`-d xdebug.mode=off` is mandatory — xdebug poisons the difftest oracle.

- [ ] **Step 2: Confirm it fails, and that cellguard names it**

```bash
cd ~/var/projects/manticore-cellguard
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig MANTICORE_PRELUDE=$PWD/prelude \
  MANTICORE_CELLGUARD=1 php -d memory_limit=2048M tools/compile_user_mir.php \
  tests/aot/cases/cellguard_<name>.php > /dev/null 2>/tmp/cg_case.err
grep "CELLGUARD raw->cell" /tmp/cg_case.err | head
```

Expected: at least one violation line. A case that reports none is not this producer — pick the next census entry.

- [ ] **Step 3: Fix it in inference**

Stop the erasure where the type is decided, not where the value is consumed. Do not add a consumer that probes the word better; §17.4 records that an inference fix re-converges the self-host fixpoint for free while a codegen A-site needs a producer/consumer co-flip that has killed this work twice.

- [ ] **Step 4: Confirm the violation is gone and the answer is right**

```bash
cd ~/var/projects/manticore-cellguard
MC_SRC=$PWD/src MC_SIG=$PWD/lib/manticore_stdlib.o.sig MANTICORE_PRELUDE=$PWD/prelude \
  MANTICORE_CELLGUARD=1 php -d memory_limit=2048M tools/compile_user_mir.php \
  tests/aot/cases/cellguard_<name>.php > /dev/null 2>/tmp/cg_case2.err
grep -c "CELLGUARD raw->cell" /tmp/cg_case2.err
```

Expected: `0`.

- [ ] **Step 5: Rebuild twice and check the fixpoint**

Ask the user before running this — it is two full builds.

```bash
cd ~/var/projects/manticore-cellguard
bin/build 2>&1 | tail -3
cp bin/manticore /tmp/cg_gen2
bin/build 2>&1 | tail -3
cmp /tmp/cg_gen2 bin/manticore && echo "gen2 == gen3"
```

Expected: `gen2 == gen3`. A byte difference means the fix did not re-converge and is a codegen co-flip in disguise — go back to Step 3.

- [ ] **Step 6: Run the suite and the scan**

```bash
cd ~/var/projects/manticore-cellguard
bash tests/aot/run.sh -j 0 2>&1 | tail -10
bash tools/cellguard_scan.sh 2>&1 | tail -20
```

Expected: no regression against the `77a10e8` baseline, and the census total lower than before.

- [ ] **Step 7: Commit**

```bash
git add src/Compile/Mir/Passes/ tests/aot/cases/cellguard_<name>.php tests/aot/expected/cellguard_<name>.out
git commit -m "cellguard: <NAME> publishes a boxed word under its cell contract"
```

---

### Task 8: Turn tagged arithmetic on

**Files:**
- Modify: `src/Compile/Mir/Passes/InferTypes.php` — the tagged-arith gate, located by TEXT
- Move: `docs/bugs/erased_arith_float_cell.php` → `tests/aot/cases/erased_arith_float_cell.php`
- Create: `tests/aot/expected/erased_arith_float_cell.out`

**Interfaces:**
- Consumes: a zero-violation census from Task 5's script.
- Produces: tagged arithmetic active for every cell operand.

**Precondition — do not start this task otherwise:** `bash tools/cellguard_scan.sh` reports `violations: 0`, and a `MANTICORE_CELLGUARD=1` self-host build reports `violations=0` in its summary.

- [ ] **Step 1: Verify the precondition**

```bash
cd ~/var/projects/manticore-cellguard
bash tools/cellguard_scan.sh 2>&1 | grep "^violations:"
```

Expected: `violations: 0`. Anything else means an unclosed producer — return to Task 7.

- [ ] **Step 2: Restore the regression case**

```bash
cd ~/var/projects/manticore-cellguard
git mv docs/bugs/erased_arith_float_cell.php tests/aot/cases/erased_arith_float_cell.php
php -d xdebug.mode=off tests/aot/cases/erased_arith_float_cell.php \
  > tests/aot/expected/erased_arith_float_cell.out
cat tests/aot/expected/erased_arith_float_cell.out
```

- [ ] **Step 3: Remove the gate**

Locate the gate by its text, not by a line number — Task 7 edits this same file first and
every line below its edit shifts:

```bash
cd ~/var/projects/manticore-cellguard
grep -n 'if (false && ($lt->kind === Type::KIND_CELL' src/Compile/Mir/Passes/InferTypes.php
```

Expected: exactly one hit. Change:

```php
        if (false && ($lt->kind === Type::KIND_CELL || $rt->kind === Type::KIND_CELL)
```

to:

```php
        if (($lt->kind === Type::KIND_CELL || $rt->kind === Type::KIND_CELL)
```

Delete the stale comment block above it that lists the three channels as reasons the routing is off, and replace it with a one-line reference to this plan.

- [ ] **Step 4: Rebuild twice, then run the suite**

Ask the user before this step.

```bash
cd ~/var/projects/manticore-cellguard
bin/build 2>&1 | tail -3
cp bin/manticore /tmp/cg_ta2
bin/build 2>&1 | tail -3
cmp /tmp/cg_ta2 bin/manticore && echo "gen2 == gen3"
bash tests/aot/run.sh -j 0 2>&1 | tail -10
```

Expected: `gen2 == gen3`, `erased_arith_float_cell` green, and no regression against baseline. A failure here names a producer the census missed — record it, fix it as a Task 7 instance, and re-run. Do not carve out the failing consumer.

- [ ] **Step 5: Commit**

```bash
git add src/Compile/Mir/Passes/InferTypes.php tests/aot/cases/erased_arith_float_cell.php \
        tests/aot/expected/erased_arith_float_cell.out
git commit -m "cellguard: tagged arithmetic on — cell is a runtime guarantee"
```

---

### Task 9: Make the cell rule fatal by default

**Files:**
- Modify: `src/Compile/Mir/Passes/TypeCheck.php` (add a `cellOnly` mode beside `reprOnly`)
- Modify: `src/Manticore/Main.php:4318-4342` (the driver's gating block)
- Modify: `tools/cellguard_scan.sh` (assert the fatal path)

**Interfaces:**
- Consumes: `$this->cellGuardViolations` from `EmitLlvm`.
- Produces: a non-zero exit from `bin/manticore compile` when a `RAW → cell` violation is emitted.

`reprOnly` is the precedent: it runs unconditionally and is already fatal, because a hit is a buffer read at the wrong type rather than a style opinion. The cell rule qualifies on the same grounds.

- [ ] **Step 1: Make violations fatal**

In the driver block at `Main.php:4318`, after emission returns, add:

```php
    // A raw word under a cell contract is a buffer read at the wrong type, not
    // a style opinion — the same grounds on which TypeCheck::$reprOnly is
    // already unconditional and fatal. See docs/superpowers/plans/2026-09-08-cellguard.md
    if (\getenv('MANTICORE_CELLGUARD') !== "0" && \count($emitter->cellGuardViolations) > 0) {
        foreach ($emitter->cellGuardViolations as $v) {
            \fwrite(\STDERR, $v . "\n");
        }
        \fwrite(\STDERR, 'cellguard: ' . (string)\count($emitter->cellGuardViolations)
            . " raw->cell violation(s)\n");
        exit(65);
    }
```

rc 65 is what the strict analyzer already uses. `MANTICORE_CELLGUARD=0` is the escape hatch;
the check is otherwise on by default, which is the point of the task.

- [ ] **Step 2: Add the negative assertion to the scan**

Append to `tools/cellguard_scan.sh`, before the final verdict:

```bash
echo "── fatal path: the positive case must fail the compile ──"
if bin/manticore compile "$out/bad.php" -o "$out/bad.bin" > "$out/bad.compile" 2>&1; then
    echo "FAIL: a raw->cell violation did not fail the build"; fail=1
else
    echo "ok: violation is fatal"
fi
```

Once the `$GLOBALS`-array producer is closed by Task 7, replace `bad.php` with a case that still violates, or drop this block and rely on the corpus being clean.

- [ ] **Step 3: Verify**

```bash
cd ~/var/projects/manticore-cellguard
bash tools/cellguard_scan.sh 2>&1 | tail -10
```

Expected: `CELLGUARD SCAN OK`.

- [ ] **Step 4: Commit**

```bash
git add src/Compile/Mir/Passes/TypeCheck.php src/Manticore/Main.php tools/cellguard_scan.sh
git commit -m "cellguard: a raw->cell violation fails the build"
```

---

### Task 10: Convert the `plausiblePtrIr` sites to assertions

**Files:**
- Modify: `src/Compile/Mir/Passes/EmitLlvm.php` (1 site), `EmitLlvmBuiltins.php` (4), `EmitLlvmControl.php` (4), `EmitLlvmExpr.php` (4), `EmitLlvmMemory.php` (2)

**Interfaces:**
- Consumes: the cell guarantee established by Tasks 7-8.
- Produces: no unvalidated dereference of a candidate pointer word.

- [ ] **Step 1: List the sites**

```bash
cd ~/var/projects/manticore-cellguard
grep -rn "plausiblePtrIr" src/
```

Expected: 15 lines across the five files above.

- [ ] **Step 2: Convert one site and prove it**

Take the erased-`foreach` classifier site first. With the operand now guaranteed boxed, the predicate's range test (`65535 < v < 2^48` followed by a dereference of `[v-8]`) becomes a tag test with no dereference. Change that one site, then:

```bash
cd ~/var/projects/manticore-cellguard
bash tests/aot/run.sh -j 0 -k foreach 2>&1 | tail -10
```

Expected: the `foreach` family green.

- [ ] **Step 3: Convert the remaining sites one commit at a time**

Each site: change it, run the family that covers it with `bash tests/aot/run.sh -j 0 -k <substr>`, commit. Do not batch — a wrong conversion is a silent miscompile, and 15 at once gives no bisect.

- [ ] **Step 4: Full verification**

Ask the user before this step.

```bash
cd ~/var/projects/manticore-cellguard
bin/build 2>&1 | tail -3
bash tests/aot/run.sh -j 0 2>&1 | tail -10
bash tools/difftest.sh 2>&1 | tail -10
```

Expected: suite and difftest no worse than the `77a10e8` baseline.

- [ ] **Step 5: Commit**

```bash
git add src/Compile/Mir/Passes/
git commit -m "cellguard: plausiblePtrIr dereferences nothing unvalidated"
```

---

## Epic exit

- `bash tools/cellguard_scan.sh` reports `violations: 0` and `CELLGUARD SCAN OK`.
- The runtime `CELLASSERT` site set is a subset of the static census.
- Tagged arithmetic is on; `erased_arith_float_cell` is in the suite and green.
- A `RAW → cell` violation fails the build by default.
- `plausiblePtrIr` dereferences nothing unvalidated.
- Suite and difftest no worse than the `77a10e8` baseline; the Linux gate green.

The Linux gate and difftest are run by the user on request.
