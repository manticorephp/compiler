<?php

namespace Compile\Mir;

/**
 * Memory model for the MIR backend (contract step #5). Maps the
 * escape verdict ({@see AllocationKind}) to a concrete reclaim
 * strategy. Selected by `--memory=<rc|arena|hybrid>`; the MIR default
 * is HYBRID — the whole point of the escape analysis is to route each
 * allocation individually.
 *
 *  - HYBRID  confined → Arena (bump-alloc, bulk-free at scope exit, no
 *            per-object RC), escaping → RcHeap (reference counted)
 *  - RC      confined → NoRefcount (freed per-local at scope exit),
 *            escaping → RcHeap
 *  - ARENA   everything → Arena; escaping needs a runtime bypass guard
 *            (mirrors the AST backend's arena L5) to stay UAF-safe
 */
final class MemoryMode
{
    public const RC     = 'rc';
    public const ARENA  = 'arena';
    public const HYBRID = 'hybrid';

    /**
     * Resolve the effective mode from the `--memory` flag value. Empty
     * (flag absent) → HYBRID for the MIR backend.
     */
    public static function resolve(string $flag): string
    {
        if ($flag === self::RC)     { return self::RC; }
        if ($flag === self::ARENA)  { return self::ARENA; }
        return self::HYBRID;
    }
}
