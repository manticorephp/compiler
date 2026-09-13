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
 *   - `probed` — it came out of a RUNTIME bit-pattern probe
 *                (`__mir_box_unknown`, `boxUnknownIfRaw`'s `select istag`):
 *                tag-valid by construction, VALUE unproven — a raw word whose
 *                bits happen to spell a tag passes through such a probe
 *                unchanged. Counted in its own bucket; never a violation,
 *                never trusted as `boxed`.
 *   - `raw`    — anything else
 *
 * A `raw` value reaching a `cell`-typed sink is a proven contract violation.
 * An `opaque` or `probed` one is not checkable here; the COUNT of them is the
 * coverage metric that says how much this instrument can see.
 *
 * A fifth bucket, `unchecked`, counts a sink whose DESTINATION SLOT TYPE could
 * not be determined statically — a dynamic-property store picks one of N slots
 * through a runtime strcmp chain, and some element/property stores are narrower
 * than the emitter's own box predicates. Never guess such a slot's type and
 * never skip it silently: count it, so the census states its own blind spots
 * instead of looking complete.
 *
 * `MANTICORE_CELLGUARD=1` turns the static census on; `MANTICORE_CELL_ASSERT=1`
 * emits a non-fatal runtime tag check at the three cell SLOT READS. Both flags
 * are read ONCE per {@see EmitLlvm::emit} into `$cellGuard` / `$cellAssert`:
 * the flag-off path does no bookkeeping at all and emits byte-identical IR.
 *
 * ⚠ RUNTIME LIMITATION — FLOATS ARE STORED UNTAGGED. `__manticore_box_float`
 * returns the raw double bits and `__manticore_is_tagged` is `ugt 0xFFF0…`, so
 * a legitimate float in a `mixed` slot (`public mixed $p = 1.5`) fires
 * `CELLASSERT` exactly like a missing box would. The assert cannot tell a raw
 * word from a double; only the static side can, which is why every site in
 * the `CELLASSERTSITE` table carries the slot's static kind and declared type
 * — a reader excludes float-shaped slots post hoc, the assert never does.
 * Tracked record: `docs/design/cellguard.md`.
 */
trait EmitLlvmCellGuard
{
    /** `MANTICORE_CELLGUARD` / `MANTICORE_CELL_ASSERT`, cached per emit(). */
    private bool $cellGuard = false;
    private bool $cellAssert = false;

    /** @var array<string, string> SSA register name (`%rN`) → 'boxed'|'opaque'|'probed' */
    private array $cellProv = [];

    /** @var array<string, int> provenance → count at a cell sink, for the summary line */
    private array $cellGuardCounts = ['boxed' => 0, 'opaque' => 0, 'probed' => 0, 'raw' => 0, 'unchecked' => 0];

    /** @var string[] one human-readable line per RAW → cell violation */
    public array $cellGuardViolations = [];

    /**
     * Per-frame ordinal of the cell sinks checked so far — the N-th sink in a
     * function's emission is the same N in every module that contains the
     * function, where its `line` is not (prelude bodies land at a different
     * offset per module). This holds because the counter is SAVED and RESTORED
     * across every nested synthetic body an emit method builds mid-function (a
     * memoized first-use helper) exactly as `$cellProv` is: a function's own
     * sinks are numbered by its own emission order alone, regardless of which
     * helpers were first-used inside it, and a helper's sinks are numbered by
     * the helper's own emission order under its own frame ({@see
     * $cellSinkFnOverride}). The ratchet keys on `(sink, fn, ord)`; report-only.
     */
    private int $cellSinkOrd = 0;

    /**
     * Attribution override for {@see checkCellSink}'s `fn=`, set for the
     * duration of a memoized first-use helper's body so its sinks report under
     * the helper's OWN symbol rather than whichever function first-used it
     * (module-dependent). Null outside a helper body — `checkCellSink` then
     * falls back to `$this->frame->name` as before.
     */
    private ?string $cellSinkFnOverride = null;

    /** @var string[] site id (index) → `fn=… kind=… slot=… decl=…`, for CELLASSERT attribution */
    private array $cellAssertSites = [];

    private function readCellGuardFlags(): void
    {
        $this->cellGuard = \getenv('MANTICORE_CELLGUARD') !== false;
        $this->cellAssert = \getenv('MANTICORE_CELL_ASSERT') !== false;
    }

    /**
     * Emit a non-fatal runtime tag check at a cell SLOT READ — the empirical
     * cross-check against the static census (`MANTICORE_CELLGUARD`'s `raw`
     * bucket). Only the three sites {@see markCellOpaque} calls at an actual
     * slot read (`emitLoadLocal`, `emitPropertyAccess`, `emitStaticProp`) call
     * this — a call return is not a slot read and a phi has no slot to assert
     * against, so neither takes an assertion. Returns IR text, or '' when the
     * flag is off (a production build pays nothing).
     *
     * `$kind` is the load's static type kind, `$slot` names the slot, `$decl`
     * is the slot's DECLARED type where one exists (a property's declaration,
     * else the load's own type) — the site table is what lets a `CELLASSERT`
     * on a float-holding `mixed` slot be recognised as such (see the trait doc).
     */
    private function emitCellAssert(string $reg, string $kind, string $slot, string $decl): string
    {
        if (!$this->cellAssert || $reg === '') { return ''; }
        $this->rt->needsCellAssert = true;
        $site = \count($this->cellAssertSites);
        $this->cellAssertSites[] = 'fn=' . ($this->frame !== null ? $this->frame->name : '(module)')
            . ' kind=' . $kind . ' slot=' . $slot . ' decl=' . $decl;
        return '  call void @__mir_assert_cell(i64 ' . $reg . ', i64 ' . (string)$site . ")\n";
    }

    /** One `CELLASSERTSITE` line per site, so `CELLASSERT site=N` is attributable from the shipped code. */
    private function logCellAssertSites(): void
    {
        foreach ($this->cellAssertSites as $i => $desc) {
            \error_log('CELLASSERTSITE id=' . (string)$i . ' ' . $desc);
        }
    }

    private function markCellBoxed(string $reg): void
    {
        if ($this->cellGuard && $reg !== '') { $this->cellProv[$reg] = 'boxed'; }
    }

    private function markCellOpaque(string $reg): void
    {
        if ($this->cellGuard && $reg !== '' && !isset($this->cellProv[$reg])) {
            $this->cellProv[$reg] = 'opaque';
        }
    }

    /** A runtime probe's output: tag-valid by construction, value unproven. */
    private function markCellProbed(string $reg): void
    {
        if ($this->cellGuard && $reg !== '') { $this->cellProv[$reg] = 'probed'; }
    }

    private function cellProvenance(string $reg): string
    {
        return $this->cellProv[$reg] ?? 'raw';
    }

    /** A pass-through transmits provenance; it does not create it. */
    private function propagateCellProvenance(string $from, string $to): void
    {
        if (!$this->cellGuard || $from === '' || $to === '' || $from === $to) { return; }
        if (isset($this->cellProv[$from])) { $this->cellProv[$to] = $this->cellProv[$from]; }
    }

    /** Registers are numbered per function, so the map must not outlive one. */
    private function resetCellGuardFrame(): void
    {
        $this->cellProv = [];
        $this->cellSinkOrd = 0;
    }

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
        if (!$this->cellGuard) { return; }
        if ($destType->kind !== \Compile\Mir\Type::KIND_CELL) { return; }
        $ord = $this->cellSinkOrd++;
        $prov = $this->cellProvenance($this->lastValue);
        $this->cellGuardCounts[$prov] = ($this->cellGuardCounts[$prov] ?? 0) + 1;
        if ($prov !== 'raw') { return; }
        $fn = $this->cellSinkFnOverride
            ?? ($this->frame !== null ? $this->frame->name : '(module)');
        $this->cellGuardViolations[] = 'CELLGUARD raw->cell ' . $sinkKind
            . ' fn=' . $fn
            . ' ord=' . (string)$ord
            . ' line=' . (string)$site->line
            . ' node=' . $site->kind;
        \error_log($this->cellGuardViolations[\count($this->cellGuardViolations) - 1]);
    }

    /**
     * A sink whose destination slot type could not be determined statically —
     * not "checked and not cell", genuinely unknown (a dynamic-property store
     * dispatching through a runtime strcmp chain, or an element/property store
     * narrower than the emitter's own box predicates). Never guess it into
     * `raw` or `boxed`, never skip it silently: count it here instead, so the
     * census states its own blind spot rather than looking complete.
     */
    private function checkCellSinkUnchecked(): void
    {
        if (!$this->cellGuard) { return; }
        $this->cellGuardCounts['unchecked'] = ($this->cellGuardCounts['unchecked'] ?? 0) + 1;
    }

    /** Coverage metric: a large `opaque`+`probed` share means this instrument is blind. */
    private function cellGuardSummary(): string
    {
        return 'CELLGUARD summary'
            . ' boxed=' . (string)($this->cellGuardCounts['boxed'] ?? 0)
            . ' opaque=' . (string)($this->cellGuardCounts['opaque'] ?? 0)
            . ' probed=' . (string)($this->cellGuardCounts['probed'] ?? 0)
            . ' raw=' . (string)($this->cellGuardCounts['raw'] ?? 0)
            . ' unchecked=' . (string)($this->cellGuardCounts['unchecked'] ?? 0)
            . ' violations=' . (string)\count($this->cellGuardViolations);
    }
}
