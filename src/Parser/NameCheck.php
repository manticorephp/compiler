<?php

namespace Parser;

use Parser\Ast\Program;

/**
 * Name-table invariant: slicing the source by a NameRef's span (bytes, 1-based
 * columns, exclusive end) yields exactly its written spelling.
 */
final class NameCheck
{
    /** @return string[] one `file:L:C message` line per violation */
    public static function verify(Program $program, string $source, string $file = ''): array
    {
        $offsets = [0];
        $n = \strlen($source);
        for ($i = 0; $i < $n; $i = $i + 1) {
            if ($source[$i] === "\n") { $offsets[] = $i + 1; }
        }
        $out = [];
        foreach ($program->names as $r) {
            $s = $r->span;
            $got = '';
            if ($s->line >= 1 && $s->endLine() === $s->line && $s->line <= \count($offsets)) {
                $base = $offsets[$s->line - 1];
                $got = \substr($source, $base + $s->column - 1, $s->endColumn() - $s->column);
            }
            if ($r->role === 'decl' && !self::declResolvesTo($r)) {
                $out[] = $file . ':' . (string)$s->line . ':' . (string)$s->column
                    . ' decl "' . $r->written . '" resolves to "' . $r->resolved . '"';
            }
            if ($got !== $r->written) {
                $out[] = $file . ':' . (string)$s->line . ':' . (string)$s->column
                    . ' name span slices to "' . $got . '", written "' . $r->written . '"';
            }
        }
        return $out;
    }

    /** A decl resolves to its written name: class-likes and functions namespace-qualified, members as the bare name. */
    private static function declResolvesTo(\Parser\Ast\NameRef $r): bool
    {
        $bare = \ltrim($r->written, '$');
        if ($r->kind === 'class' || $r->kind === 'function') {
            return $r->resolved === $r->written
                || \str_ends_with($r->resolved, '\\' . $r->written);
        }
        return $r->resolved === $bare;
    }
}
