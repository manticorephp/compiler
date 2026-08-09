<?php

namespace Compile\Mir;

/**
 * Structural child iterator for MIR nodes — the single source of
 * truth for "what are the sub-nodes of this node". Both {@see
 * Passes\Verify} and {@see Passes\InferEffects} recurse through it,
 * so a new node kind only needs wiring here once.
 *
 * Returns *value/control* children only (the things a generic walk
 * should descend into). Leaf payloads (literal values, op strings,
 * names) are not nodes and are omitted. Definition-site semantics
 * (which names a node *binds*) stay in the callers, since that is
 * pass-specific.
 *
 * The answer itself now lives on the node ({@see Node::children}). This used to
 * be a chain of ~60 `$n->kind === Node::KIND_*` STRING comparisons plus 46
 * `as*()` narrowing helpers, and profiling charged 16.5% of the compiler's own
 * samples to `__mir_str_eq` with 71% of the attributable part under this one
 * function. The helpers existed only because narrowing a `Node` FAILS inside a
 * trait, and every pass is a trait on one host — inside the subclass itself
 * `$this` is already narrow, so both the chain and the helpers disappear.
 *
 * The entry point stays, because ~100 call sites and the documented contract
 * above are about `Walk`, not about where the switch happens to live.
 */
final class Walk
{
    /** @return Node[] */
    public static function children(Node $n): array
    {
        return $n->children();
    }
}
