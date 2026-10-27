<?php

namespace Analyze;

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
}
