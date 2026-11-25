<?php

namespace Compile\Mir;

/**
 * Tarjan's strongly connected components of a call graph, in the order they
 * complete — every component after all the components it calls. The order a
 * callees-first summary fixpoint ({@see EscapeSummaries}, {@see NothrowSummary})
 * walks: every member of a component reaches every other, so the component
 * has ONE answer, made of the members' own facts and the already-final answers
 * of its outside callees.
 */
final class CallGraphScc
{
    /**
     * Iterative, with the DFS stack as two parallel lists. Every name in
     * `$names` must have a `$callees` entry, and every callee must be in
     * `$names`.
     *
     * @param string[] $names
     * @param array<string, string[]> $callees
     * @return string[][]
     */
    public static function components(array $names, array $callees): array
    {
        /** @var array<string, int> $index */
        $index = [];
        /** @var array<string, int> $low */
        $low = [];
        /** @var array<string, bool> $onStack */
        $onStack = [];
        /** @var string[] $stack */
        $stack = [];
        /** @var string[][] $out */
        $out = [];
        $next = 0;
        foreach ($names as $root) {
            if (isset($index[$root])) { continue; }
            /** @var string[] $workNode */
            $workNode = [$root];
            /** @var int[] $workPos */
            $workPos = [0];
            $index[$root] = $next;
            $low[$root] = $next;
            $next = $next + 1;
            $stack[] = $root;
            $onStack[$root] = true;
            while ($workNode !== []) {
                $top = \count($workNode) - 1;
                $u = $workNode[$top];
                $i = $workPos[$top];
                $succ = $callees[$u];
                if ($i < \count($succ)) {
                    $workPos[$top] = $i + 1;
                    $w = $succ[$i];
                    if (!isset($index[$w])) {
                        $index[$w] = $next;
                        $low[$w] = $next;
                        $next = $next + 1;
                        $stack[] = $w;
                        $onStack[$w] = true;
                        $workNode[] = $w;
                        $workPos[] = 0;
                    } elseif (isset($onStack[$w]) && $index[$w] < $low[$u]) {
                        $low[$u] = $index[$w];
                    }
                    continue;
                }
                \array_pop($workNode);
                \array_pop($workPos);
                if ($workNode !== []) {
                    $p = $workNode[\count($workNode) - 1];
                    if ($low[$u] < $low[$p]) { $low[$p] = $low[$u]; }
                }
                if ($low[$u] === $index[$u]) {
                    /** @var string[] $scc */
                    $scc = [];
                    while (true) {
                        $w = \array_pop($stack);
                        unset($onStack[$w]);
                        $scc[] = $w;
                        if ($w === $u) { break; }
                    }
                    $out[] = $scc;
                }
            }
        }
        return $out;
    }

    /**
     * One stats line on the shape of the components: the largest, how many
     * functions sit in a component of two or more, and the longest chain of
     * components (callees first, so each depth is final when it is read).
     *
     * @param string[][] $sccs from {@see components}
     * @param array<string, string[]> $callees
     */
    public static function shape(array $sccs, array $callees): string
    {
        /** @var array<string, int> $depth */
        $depth = [];
        $largest = 0;
        $inCycles = 0;
        $maxDepth = 0;
        foreach ($sccs as $scc) {
            $n = \count($scc);
            if ($n > $largest) { $largest = $n; }
            if ($n > 1) { $inCycles = $inCycles + $n; }
            $d = 0;
            foreach ($scc as $m) {
                foreach ($callees[$m] as $c) {
                    $cd = $depth[$c] ?? 0;
                    if ($cd > $d) { $d = $cd; }
                }
            }
            $d = $d + 1;
            foreach ($scc as $m) { $depth[$m] = $d; }
            if ($d > $maxDepth) { $maxDepth = $d; }
        }
        return "call-graph sccs: largest=" . (string)$largest . " in-cycles=" . (string)$inCycles
            . " depth=" . (string)$maxDepth;
    }
}
