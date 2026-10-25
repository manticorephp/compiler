<?php

namespace Parser;

use Parser\Ast\AttributeNode;
use Parser\Ast\Program;
use Parser\Ast\Span;

/**
 * Range invariant checker. Walks any AST generically (public properties, like
 * {@see Dump}) and reports, one `file:L:C message` line each:
 *
 *   - a node whose end precedes its start;
 *   - a child range that is not inside its parent's range;
 *   - two siblings that overlap (checked after sorting by start, so a desugared
 *     node whose fields are not in source order is fine). The one exemption:
 *     two siblings that BOTH have exactly the parent's range — the parser gives
 *     every node of an interpolated string the whole string's range;
 *   - with $requireEnds, a node with no end at all.
 *
 * An AttributeNode reachable twice (the attributes of `#[A] public $a, $b;` hang
 * off both declarators) is checked once, under the first parent that reaches it;
 * any other node placed twice is reported like any overlap.
 *
 * A node with endLine === 0 is "unset": skipped, but its descendants are still
 * checked against the nearest ranged ancestor. Objects without a `span` (Block,
 * ArrayElement, MatchArm, …) are transparent.
 */
final class RangeCheck
{
    /** @var string[] */
    private array $out = [];
    private bool $requireEnds = false;
    private string $defaultFile = '';
    /** @var array<int,bool> AttributeNodes already checked (shared by sibling declarators) */
    private array $seen = [];

    /** @return string[] */
    public static function verify(Program $program, bool $requireEnds = false, string $file = ''): array
    {
        $c = new self();
        $c->requireEnds = $requireEnds;
        $c->defaultFile = $file;
        $c->walk($program);
        return $c->out;
    }

    /**
     * Check $v and everything under it; return the nearest ranged nodes found,
     * for the caller to compare against its own range.
     *
     * @return Span[]
     */
    private function walk(mixed $v): array
    {
        $found = [];
        if (\is_array($v)) {
            foreach ($v as $item) {
                foreach ($this->walk($item) as $n) { $found[] = $n; }
            }
            return $found;
        }
        if (!\is_object($v) || $v instanceof Span) {
            return $found;
        }
        $vars = \get_object_vars($v);
        $span = $vars['span'] ?? null;
        if ($v instanceof AttributeNode && $span instanceof Span) {
            $id = \spl_object_id($v);
            if (isset($this->seen[$id])) { return $found; }
            $this->seen[$id] = true;
        }
        $kids = [];
        foreach ($vars as $key => $value) {
            if ($key === 'span' || $key === 'endSpan' || $key === 'kind') { continue; }
            foreach ($this->walk($value) as $n) { $kids[] = $n; }
        }
        if (!($span instanceof Span)) {
            return $kids;
        }
        if ($span->endLine === 0) {
            if ($this->requireEnds) { $this->report($span, 'node has no end position'); }
            return $kids;
        }
        if ($this->before($span->endLine, $span->endColumn, $span->startLine(), $span->startColumn())) {
            $this->report($span, 'end precedes start');
        }
        $this->checkKids($span, $kids);
        $found[] = $span;
        return $found;
    }

    /** @param Span[] $kids */
    private function checkKids(Span $parent, array $kids): void
    {
        $n = \count($kids);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $s = $kids[$i];
            if ($this->before($s->startLine(), $s->startColumn(), $parent->startLine(), $parent->startColumn())
                || $this->before($parent->endLine, $parent->endColumn, $s->endLine, $s->endColumn)) {
                $this->report($s, 'child range ' . $this->str($s) . ' outside parent ' . $this->str($parent));
            }
        }
        // insertion sort by start; sibling lists are short
        $order = [];
        for ($i = 0; $i < $n; $i = $i + 1) { $order[] = $i; }
        for ($i = 1; $i < $n; $i = $i + 1) {
            $cur = $order[$i];
            $cs = $kids[$cur];
            $j = $i - 1;
            while ($j >= 0) {
                $ps = $kids[$order[$j]];
                if (!$this->before($cs->startLine(), $cs->startColumn(), $ps->startLine(), $ps->startColumn())) { break; }
                $order[$j + 1] = $order[$j];
                $j = $j - 1;
            }
            $order[$j + 1] = $cur;
        }
        for ($i = 1; $i < $n; $i = $i + 1) {
            $a = $kids[$order[$i - 1]];
            $b = $kids[$order[$i]];
            if ($this->str($a) === $this->str($parent) && $this->str($b) === $this->str($parent)) { continue; }
            if ($this->before($b->startLine(), $b->startColumn(), $a->endLine, $a->endColumn)) {
                $this->report($b, 'sibling range ' . $this->str($b) . ' overlaps ' . $this->str($a));
            }
        }
    }

    private function before(int $l1, int $c1, int $l2, int $c2): bool
    {
        return $l1 < $l2 || ($l1 === $l2 && $c1 < $c2);
    }

    private function str(Span $s): string
    {
        return (string)$s->startLine() . ':' . (string)$s->startColumn()
            . '-' . (string)$s->endLine . ':' . (string)$s->endColumn;
    }

    private function report(Span $at, string $message): void
    {
        $file = $at->file !== '' ? $at->file : $this->defaultFile;
        $this->out[] = $file . ':' . (string)$at->startLine() . ':' . (string)$at->startColumn() . ' ' . $message;
    }
}
