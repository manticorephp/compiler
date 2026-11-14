<?php

namespace Analyze;

use Parser\Ast\ClassDecl;
use Parser\Ast\NameRef;
use Parser\Ast\Span;

/** Looks up the parser name-table entry behind a declaration-level diagnostic. */
final class NameRanges
{
    private static function key(int $line, int $col): int
    {
        return $line * 1048576 + $col;
    }

    private static function inside(NameRef $r, Span $within, ?Span $after): bool
    {
        $at = self::key($r->span->startLine(), $r->span->startColumn());
        if ($at < self::key($within->startLine(), $within->startColumn())) { return false; }
        if ($within->endLine() > 0 && $at >= self::key($within->endLine(), $within->endColumn())) { return false; }
        if ($after === null) { return true; }
        if ($after->endLine() > 0) { return $at >= self::key($after->endLine(), $after->endColumn()); }
        return true;
    }

    /**
     * First class USE entry (role '') resolving to $name inside $within and past
     * the end of $after; null when the table is empty or has no such entry.
     *
     * @param NameRef[] $names
     */
    public static function useOf(array $names, string $name, Span $within, ?Span $after = null): ?NameRef
    {
        $want = \strtolower(\ltrim($name, '\\'));
        $best = null;
        foreach ($names as $r) {
            if ($r->kind !== 'class' || $r->role !== '') { continue; }
            if (\strtolower(\ltrim($r->resolved, '\\')) !== $want) { continue; }
            if (!self::inside($r, $within, $after)) { continue; }
            if ($best === null || self::key($r->span->startLine(), $r->span->startColumn()) < self::key($best->span->startLine(), $best->span->startColumn())) { $best = $r; }
        }
        return $best;
    }

    /**
     * First declaration-name entry (role `decl`) spelled $name inside $within.
     *
     * @param NameRef[] $names
     */
    public static function declOf(array $names, string $name, Span $within): ?NameRef
    {
        $best = null;
        foreach ($names as $r) {
            if ($r->role !== 'decl' || \strtolower($r->written) !== \strtolower($name)) { continue; }
            if (!self::inside($r, $within, null)) { continue; }
            if ($best === null || self::key($r->span->startLine(), $r->span->startColumn()) < self::key($best->span->startLine(), $best->span->startColumn())) { $best = $r; }
        }
        return $best;
    }
    /**
     * Class USE entries inside the class, in source order, that follow the
     * class name's decl entry (the span start when the table has none).
     *
     * @param NameRef[] $names
     * @return NameRef[]
     */
    private static function classRefs(array $names, ClassDecl $c): array
    {
        $from = self::key($c->span->startLine(), $c->span->startColumn());
        $short = \strtolower(\substr(\strrchr('\\' . $c->name, '\\'), 1));
        foreach ($names as $r) {
            if ($r->role === 'decl' && $r->kind === 'class' && \strtolower($r->written) === $short
                && self::inside($r, $c->span, null)) {
                $from = self::key($r->span->startLine(), $r->span->startColumn());
                break;
            }
        }
        $byPos = [];
        foreach ($names as $r) {
            if ($r->kind !== 'class' || $r->role !== '' || !self::inside($r, $c->span, null)) { continue; }
            $k = self::key($r->span->startLine(), $r->span->startColumn());
            if ($k > $from) { $byPos[$k] = $r; }
        }
        \ksort($byPos);
        return \array_values($byPos);
    }

    private static function sameClass(NameRef $r, string $name): bool
    {
        return \strtolower(\ltrim($r->resolved, '\\')) === \strtolower(\ltrim($name, '\\'));
    }

    /**
     * Entry of an `extends`/`implements` name: the parser emits the header names
     * first and in order, so they are the first count(extends)+count(implements)
     * class entries after the class name.
     *
     * @param NameRef[] $names
     */
    public static function headerOf(array $names, ClassDecl $c, string $name): ?NameRef
    {
        $refs = self::classRefs($names, $c);
        $n = \min(\count($refs), \count($c->extends) + \count($c->implements));
        for ($i = 0; $i < $n; $i++) {
            if (self::sameClass($refs[$i], $name)) { return $refs[$i]; }
        }
        return null;
    }

    /**
     * Entry of a trait `use` name: a class entry past the header names that lies
     * outside every member span (members, with their attributes, carry their own
     * type mentions; a `use` statement stands between them).
     *
     * @param NameRef[] $names
     */
    public static function traitOf(array $names, ClassDecl $c, string $name): ?NameRef
    {
        $refs = self::classRefs($names, $c);
        $skip = \count($c->extends) + \count($c->implements);
        $members = [];
        foreach ($c->properties as $m) { $members[] = $m->span; }
        foreach ($c->methods as $m) { $members[] = $m->span; }
        foreach ($c->consts as $m) { $members[] = $m->span; }
        foreach ($c->cases as $m) { $members[] = $m->span; }
        for ($i = $skip; $i < \count($refs); $i++) {
            if (!self::sameClass($refs[$i], $name)) { continue; }
            $in = false;
            foreach ($members as $sp) {
                if ($sp->endLine() > 0 && self::inside($refs[$i], $sp, null)) { $in = true; break; }
            }
            if (!$in) { return $refs[$i]; }
        }
        return null;
    }
}
