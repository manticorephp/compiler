<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\MemoryMode;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\Walk;

/**
 * Memory-mode overlay (contract step #5). Keeps {@see InferAllocKind}
 * a pure escape analysis (RcHeap / NoRefcount) and folds the chosen
 * {@see MemoryMode} on top, so the same escape verdict can drive rc,
 * arena, or hybrid reclaim without re-running the analysis.
 *
 * Remap of a confined (NoRefcount) allocation:
 *  - HYBRID → Arena   (bump-alloc, bulk-free at scope exit)
 *  - ARENA  → Arena
 *  - RC     → NoRefcount (unchanged)
 * Escaping (RcHeap) stays RcHeap under rc/hybrid. Under ARENA it also
 * routes to Arena (the emission side adds the escape bypass guard).
 */
final class ApplyMemoryMode implements Pass
{
    public const NAME = 'apply-memory-mode';

    private string $mode;

    public function __construct(string $mode)
    {
        $this->mode = MemoryMode::resolve($mode);
    }

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferAllocKind::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->remap($fn->body);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function remap(Node $n): void
    {
        $kind = $n->allocKind;
        if ($kind !== null) {
            $n->allocKind = $this->mapped($kind);
        }
        foreach (Walk::children($n) as $c) { $this->remap($c); }
    }

    private function mapped(string $kind): string
    {
        if ($this->mode === MemoryMode::RC) {
            return $kind;
        }
        if ($this->mode === MemoryMode::ARENA) {
            // Arena-everything: confined and escaping both bump-allocate.
            return AllocationKind::ARENA;
        }
        // HYBRID: confined → Arena, escaping stays RcHeap.
        if ($kind === AllocationKind::NO_REFCOUNT) {
            return AllocationKind::ARENA;
        }
        return $kind;
    }
}
