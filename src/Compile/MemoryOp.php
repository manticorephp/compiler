<?php

namespace Compile;

/**
 * Semantic record of one refcount operation emitted by the
 * compiler. Captures everything the audit pass needs without
 * re-deriving it from LLVM IR.
 *
 * Lives next to {@see MemoryAbi}. The eventual lowering layer
 * walks a list of these per function (or per block) and emits the
 * matching `__manticore_*_retain` / `__manticore_*_release` calls;
 * elision / coalescing passes (B3) operate on this representation,
 * not on raw IR.
 *
 * For V1 the recording happens alongside the existing inline emit.
 */
final class MemoryOp
{
    public const KIND_RETAIN = 'retain';
    public const KIND_RELEASE = 'release';
    public const KIND_COW = 'cow';
    public const KIND_POSSIBLE_ROOT = 'possible_root';

    public const FLAVOR_ASSOC = 'assoc';
    public const FLAVOR_OBJ = 'obj';
    public const FLAVOR_VEC = 'vec';

    public function __construct(
        /** 'retain' | 'release' | 'cow' | 'possible_root' */
        public readonly string $kind,
        /** 'assoc' | 'obj' | 'vec' */
        public readonly string $flavor,
        /** Origin tag — e.g. `assign-Variable-x`, `methodcall-arg-foo-1`,
         *  `scope-exit-redirect`. Stable across recompiles so audits
         *  can grep / diff. */
        public readonly string $site,
        /** Function the op lives in, for cross-function grouping. */
        public readonly string $functionName,
    ) {}

    public function format(): string
    {
        return '[MEM] ' . $this->functionName
            . ' ' . $this->kind . '/' . $this->flavor
            . ' site=' . $this->site;
    }
}
