<?php

namespace Semantic;

use Lexer\TokenKind;

/** Names introduced by `use A\B as C;` import statements (file level only: trait adaptations and closure `use` are not imports). */
final class ImportAliases
{
    public static function has(string $text, string $alias): bool
    {
        $toks = (new \Lexer\Lexer())->scan($text, false, true, true);
        $n = \count($toks);
        $depth = 0;
        $base = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if ($t->kind === TokenKind::OpenBrace) { $depth++; continue; }
            if ($t->kind === TokenKind::CloseBrace) { $depth--; if ($depth < $base) { $base = 0; } continue; }
            if ($t->kind !== TokenKind::Keyword) { continue; }
            if ($t->lexeme === 'namespace' && ($toks[$i + 1]->kind ?? '') !== TokenKind::Backslash) {
                $j = $i + 1;
                while ($j < $n && ($toks[$j]->kind === TokenKind::Identifier || $toks[$j]->kind === TokenKind::Backslash)) { $j++; }
                if (($toks[$j]->kind ?? '') === TokenKind::OpenBrace) { $depth++; $base = $depth; $i = $j; }
                continue;
            }
            if ($t->lexeme !== 'use' || $depth !== $base) { continue; }
            $p = $i > 0 ? $toks[$i - 1]->kind : '';
            if ($p === TokenKind::Arrow || $p === TokenKind::NullsafeArrow || $p === TokenKind::DoubleColon) { continue; }
            for ($j = $i + 1; $j < $n && $toks[$j]->kind !== TokenKind::Semicolon && $toks[$j]->kind !== TokenKind::OpenParen && $toks[$j]->kind !== TokenKind::Eof; $j++) {
                if ($toks[$j]->kind === TokenKind::Keyword && $toks[$j]->lexeme === 'as' && \strcasecmp($toks[$j + 1]->lexeme ?? '', $alias) === 0) { return true; }
            }
            $i = $j;
        }
        return false;
    }
}
