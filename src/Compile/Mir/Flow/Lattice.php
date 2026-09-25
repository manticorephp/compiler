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
 * every statement that is not control flow. Control nodes (If_, loops, Switch_,
 * TryCatch_, Break_, Continue_, Goto_, Label_, Block) never reach it, with one
 * exception: a Foreach_ is passed at the top of EVERY iteration, after the head
 * join, and that call is its key/value binding.
 *
 * `onEdge` is called once per control edge after the whole body converged, in
 * the order the edges were first taken: `$out` is the state leaving the
 * predecessor, `$joined` the merged state at the target. See {@see Forward} for
 * which node `$at` names per kind.
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
    public function onEdge(string $kind, Node $at, array $out, array $joined): void;
}
