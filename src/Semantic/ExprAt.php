<?php

namespace Semantic;

use Analyze\AstWalk;
use Parser\Ast\Expr;
use Parser\Ast\Program;
use Parser\Ast\RangedSpan;

/**
 * Position -> expression. Every ranged expression is keyed by its (line, column) start and end,
 * compared as pairs (no packed integer, so a line of any length is safe). Ranges end exclusive and
 * nest, so the innermost node at a point is found by a binary search for the last start <= point,
 * then climbing parents until one contains it. Under error recovery ranges may overlap partially;
 * `chain` keeps only the nodes whose range contains the point.
 */
final class ExprAt
{
    /** @var Expr[] */
    private array $nodes = [];
    /** @var int[] */
    private array $sl = [];
    /** @var int[] */
    private array $sc = [];
    /** @var int[] */
    private array $el = [];
    /** @var int[] */
    private array $ec = [];
    /** @var int[] index of the enclosing node, -1 at the top */
    private array $parent = [];

    public static function build(Program $p): ExprAt
    {
        $walk = new AstWalk();
        $walk->stmts($p->statements);
        /** @var int[] $order */
        $order = [];
        /** @var int[] $sl */
        $sl = [];
        /** @var int[] $sc */
        $sc = [];
        /** @var int[] $el */
        $el = [];
        /** @var int[] $ec */
        $ec = [];
        foreach ($walk->exprs as $i => $x) {
            $sp = $x->span;
            if (!($sp instanceof RangedSpan) || $sp->endLine() === 0) { continue; }
            $order[] = $i;
            $sl[$i] = $sp->startLine();
            $sc[$i] = $sp->startColumn();
            $el[$i] = $sp->endLine();
            $ec[$i] = $sp->endColumn();
        }
        // start asc, end desc (outer first), pre-order index last: equal ranges keep parent before child
        \usort($order, function (int $a, int $b) use ($sl, $sc, $el, $ec): int {
            if ($sl[$a] !== $sl[$b]) { return $sl[$a] <=> $sl[$b]; }
            if ($sc[$a] !== $sc[$b]) { return $sc[$a] <=> $sc[$b]; }
            if ($el[$a] !== $el[$b]) { return $el[$b] <=> $el[$a]; }
            if ($ec[$a] !== $ec[$b]) { return $ec[$b] <=> $ec[$a]; }
            return $a <=> $b;
        });
        $at = new ExprAt();
        /** @var int[] $stack */
        $stack = [];
        foreach ($order as $i) {
            while (\count($stack) > 0) {
                $t = $stack[\count($stack) - 1];
                // pop while the top ends at or before this start
                if ($at->el[$t] < $sl[$i] || ($at->el[$t] === $sl[$i] && $at->ec[$t] <= $sc[$i])) { \array_pop($stack); } else { break; }
            }
            $at->parent[] = \count($stack) === 0 ? -1 : $stack[\count($stack) - 1];
            $at->nodes[] = $walk->exprs[$i];
            $at->sl[] = $sl[$i];
            $at->sc[] = $sc[$i];
            $at->el[] = $el[$i];
            $at->ec[] = $ec[$i];
            $stack[] = \count($at->nodes) - 1;
        }
        return $at;
    }

    private function contains(int $i, int $line, int $col): bool
    {
        if ($this->sl[$i] > $line || ($this->sl[$i] === $line && $this->sc[$i] > $col)) { return false; }
        return $this->el[$i] > $line || ($this->el[$i] === $line && $this->ec[$i] > $col);
    }

    private function indexAt(int $line, int $col): int
    {
        $lo = 0;
        $hi = \count($this->nodes) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = \intdiv($lo + $hi, 2);
            if ($this->sl[$mid] < $line || ($this->sl[$mid] === $line && $this->sc[$mid] <= $col)) { $found = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        while ($found >= 0 && !$this->contains($found, $line, $col)) { $found = $this->parent[$found]; }
        return $found;
    }

    public function innermost(int $line, int $col): ?Expr
    {
        $i = $this->indexAt($line, $col);
        return $i < 0 ? null : $this->nodes[$i];
    }

    /** @return Expr[] outermost first, only nodes whose range contains the point */
    public function chain(int $line, int $col): array
    {
        /** @var Expr[] $out */
        $out = [];
        $i = $this->indexAt($line, $col);
        while ($i >= 0) {
            if ($this->contains($i, $line, $col)) { \array_unshift($out, $this->nodes[$i]); }
            $i = $this->parent[$i];
        }
        return $out;
    }
}
