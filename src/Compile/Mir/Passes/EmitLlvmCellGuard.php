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
 */
trait EmitLlvmCellGuard
{
    /** @var array<string, string> SSA register name (`%rN`) → 'boxed'|'opaque' */
    private array $cellProv = [];

    /** @var array<string, int> violation kind → count, for the summary line */
    private array $cellGuardCounts = ['boxed' => 0, 'opaque' => 0, 'raw' => 0];

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

    /** A pass-through transmits provenance; it does not create it. */
    private function propagateCellProvenance(string $from, string $to): void
    {
        if ($from === '' || $to === '' || $from === $to) { return; }
        if (isset($this->cellProv[$from])) { $this->cellProv[$to] = $this->cellProv[$from]; }
    }

    /** Registers are numbered per function, so the map must not outlive one. */
    private function resetCellGuardFrame(): void
    {
        $this->cellProv = [];
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
}
