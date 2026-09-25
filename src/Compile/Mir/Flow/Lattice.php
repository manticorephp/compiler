<?php

namespace Compile\Mir\Flow;

use Compile\Mir\MirCatch;
use Compile\Mir\Node;

/**
 * The client half of {@see Forward}: a join-semilattice of per-local facts.
 * A state maps a local's name to an encoded fact; an absent name is the
 * lattice's own business (bottom, or "unknown").
 *
 * `transfer` sees every expression node AFTER its children were evaluated, and
 * every statement that is not pure control flow. Of the nodes that steer
 * control, these DO reach it: Return_ and Throw_ (after their operand, before
 * the jump), Ternary, NullCoalesce_ and Match_ (once, on the join of their
 * arms), and Foreach_ — at the top of EVERY iteration, after the head join, as
 * its key/value binding. If_, While_, For_, DoWhile_, Switch_, TryCatch_,
 * Break_, Continue_, Goto_, Label_ and Block never do.
 *
 * `onEdge` is called once per control edge after the whole body converged, in
 * the order the edges were first taken. `$at` names the edge's construct,
 * `$pred` the node that ends the predecessor path (null only for an implicit
 * source: the exceptional path into a finally, or the fall-off return of an
 * empty body — whose `$at` is the body Block itself). `$out` is the state
 * leaving the predecessor, `$joined` the merged state at the target. The
 * per-kind table is on {@see Forward}.
 */
interface Lattice
{
    /** @return array<string, int> */
    public function entry(): array;

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    public function join(array $a, array $b): array;

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     */
    public function equal(array $a, array $b): bool;

    /**
     * @param array<string, int> $in
     * @return array<string, int>
     */
    public function transfer(Node $stmt, array $in): array;

    /**
     * Entry of one catch arm: `$in` is the join over the try entry and every
     * point inside the try body; the arm binds its exception variable here.
     *
     * @param array<string, int> $in
     * @return array<string, int>
     */
    public function catchEntry(MirCatch $c, array $in): array;

    /**
     * @param array<string, int> $out
     * @param array<string, int> $joined
     */
    public function onEdge(string $kind, Node $at, ?Node $pred, array $out, array $joined): void;
}
