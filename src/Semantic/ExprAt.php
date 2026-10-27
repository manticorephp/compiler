<?php

namespace Semantic;

use Analyze\AstWalk;
use Parser\Ast\Expr;
use Parser\Ast\Program;
use Parser\Ast\RangedSpan;

/**
 * Position -> expression. Every ranged expression is keyed by (line * 2^20 + column);
 * ranges end exclusive and nest, so the innermost node at a point is found by a binary
 * search for the last start <= point, then climbing parents until one contains it.
 */
final class ExprAt
{
    private const COL = 1048576;

    /** @var Expr[] */
    private array $nodes = [];
    /** @var int[] */
    private array $starts = [];
    /** @var int[] */
    private array $ends = [];
    /** @var int[] index of the enclosing node, -1 at the top */
    private array $parent = [];

    public static function build(Program $p): ExprAt
    {
        $walk = new AstWalk();
        $walk->stmts($p->statements);
        /** @var array<int, int> $order */
        $order = [];
        /** @var int[] $s */
        $s = [];
        /** @var int[] $e */
        $e = [];
        foreach ($walk->exprs as $i => $x) {
            $sp = $x->span;
            if (!($sp instanceof RangedSpan) || $sp->endLine() === 0) { continue; }
            $order[] = $i;
            $s[$i] = $sp->startLine() * self::COL + $sp->startColumn();
            $e[$i] = $sp->endLine() * self::COL + $sp->endColumn();
        }
        // start asc, end desc (outer first), pre-order index last: equal ranges keep parent before child
        \usort($order, function (int $a, int $b) use ($s, $e): int {
            if ($s[$a] !== $s[$b]) { return $s[$a] <=> $s[$b]; }
            if ($e[$a] !== $e[$b]) { return $e[$b] <=> $e[$a]; }
            return $a <=> $b;
        });
        $at = new ExprAt();
        /** @var int[] $stack */
        $stack = [];
        foreach ($order as $i) {
            while (\count($stack) > 0 && $at->ends[$stack[\count($stack) - 1]] <= $s[$i]) { \array_pop($stack); }
            $at->parent[] = \count($stack) === 0 ? -1 : $stack[\count($stack) - 1];
            $at->nodes[] = $walk->exprs[$i];
            $at->starts[] = $s[$i];
            $at->ends[] = $e[$i];
            $stack[] = \count($at->nodes) - 1;
        }
        return $at;
    }

    private function indexAt(int $line, int $col): int
    {
        $key = $line * self::COL + $col;
        $lo = 0;
        $hi = \count($this->starts) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = \intdiv($lo + $hi, 2);
            if ($this->starts[$mid] <= $key) { $found = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        while ($found >= 0 && $this->ends[$found] <= $key) { $found = $this->parent[$found]; }
        return $found;
    }

    public function innermost(int $line, int $col): ?Expr
    {
        $i = $this->indexAt($line, $col);
        return $i < 0 ? null : $this->nodes[$i];
    }

    /** @return Expr[] outermost first */
    public function chain(int $line, int $col): array
    {
        /** @var Expr[] $out */
        $out = [];
        $i = $this->indexAt($line, $col);
        while ($i >= 0) {
            \array_unshift($out, $this->nodes[$i]);
            $i = $this->parent[$i];
        }
        return $out;
    }
}
