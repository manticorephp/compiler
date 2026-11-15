<?php

namespace Semantic;

use Lexer\Token;
use Lexer\TokenKind;

/**
 * The class names written inside the type-carrying tags of a file's docblocks (`@param Foo $x`, `@return Foo[]`,
 * `@var array<int, Foo>`, `@method`, `@template T of Foo` ...), with their positions and the FQN each resolves to.
 * Prose lines and non-type tags are never read. Docblocks, `namespace` and top-level `use` clauses come from the
 * lexer's tokens (so strings, plain comments, heredocs and inline HTML are skipped by the lexer itself); the
 * namespace and `use` map in force at each docblock are tracked from the same pass (a file may hold several
 * namespace blocks).
 */
final class DocblockNames
{
    private const TYPE_TAGS = [
        'param' => 1, 'return' => 1, 'var' => 1, 'throws' => 1, 'property' => 1, 'property-read' => 1, 'property-write' => 1,
        'extends' => 1, 'implements' => 1, 'use' => 1, 'mixin' => 1, 'template-extends' => 1, 'template-implements' => 1,
        'template-use' => 1, 'param-out' => 1, 'assert' => 1, 'assert-if-true' => 1, 'assert-if-false' => 1,
        'self-out' => 1, 'this-out' => 1,
    ];

    private const PROSE_STARTERS = ['a' => 1, 'an' => 1, 'the' => 1, 'this' => 1, 'these' => 1, 'those' => 1, 'it' => 1, 'its' => 1, 'if' => 1, 'when' => 1, 'returns' => 1, 'whether' => 1, 'all' => 1, 'any' => 1, 'one' => 1, 'some' => 1, 'no' => 1];

    private const WORDS = ['min' => 1, 'max' => 1, 'is' => 1, 'not' => 1, 'of' => 1, 'as' => 1, 'empty' => 1, 'array' => 1, 'list' => 1];

    /**
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}> [written name, line, 1-based byte column, FQN]; a name never spans lines
     */
    public static function scan(string $text): array
    {
        $out = [];
        if (\strpos($text, '/**') === false) { return $out; }
        $toks = (new \Lexer\Lexer())->scan($text, false, true, true);
        /** @var array<string, int> $declared template and type-alias names seen so far in the file */
        $declared = [];
        /** @var array<string, string> $curUses */
        $curUses = [];
        $curNs = '';
        $depth = 0;
        $base = 0;
        $braced = false;
        $n = \count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            $k = $t->kind;
            if ($k === TokenKind::DocComment) {
                if (\strpos($t->lexeme, '@') === false) { continue; }
                $before = \count($out);
                self::doc($t->lexeme, $t->line, $t->column - 1, $declared, $out);
                for ($j = $before; $j < \count($out); $j++) { $out[$j][] = DocNames::qualify($out[$j][0], $curNs, $curUses); }
            } elseif ($k === TokenKind::OpenBrace) {
                $depth++;
            } elseif ($k === TokenKind::CloseBrace) {
                $depth--;
                if ($braced && $depth < $base) {
                    $curNs = '';
                    $curUses = [];
                    $braced = false;
                    $base = 0;
                }
            } elseif ($k === TokenKind::Keyword && $t->lexeme === 'namespace' && !self::isMember($toks, $i)) {
                $j = $i + 1;
                $name = '';
                while ($j < $n && ($toks[$j]->kind === TokenKind::Identifier || $toks[$j]->kind === TokenKind::Backslash)) { $name .= $toks[$j]->lexeme; $j++; }
                if ($j >= $n || ($name[0] ?? '') === '\\') { continue; }
                $kk = $toks[$j]->kind;
                if ($kk !== TokenKind::Semicolon && $kk !== TokenKind::OpenBrace) { continue; }
                $curNs = $name;
                $curUses = [];
                if ($kk === TokenKind::OpenBrace) {
                    $depth++;
                    $braced = true;
                    $base = $depth;
                }
                $i = $j;
            } elseif ($k === TokenKind::Keyword && $t->lexeme === 'use' && $depth === ($braced ? $base : 0) && !self::isMember($toks, $i)) {
                $j = $i + 1;
                while ($j < $n && $toks[$j]->kind !== TokenKind::Semicolon && $toks[$j]->kind !== TokenKind::OpenParen && $toks[$j]->kind !== TokenKind::Eof) { $j++; }
                if ($j >= $n || $toks[$j]->kind !== TokenKind::Semicolon) { continue; }
                self::useClause(\array_slice($toks, $i + 1, $j - $i - 1), $curUses);
                $i = $j;
            }
        }
        return $out;
    }

    /** @param Token[] $toks */
    private static function isMember(array $toks, int $i): bool
    {
        if ($i === 0) { return false; }
        $p = $toks[$i - 1]->kind;
        return $p === TokenKind::Arrow || $p === TokenKind::NullsafeArrow || $p === TokenKind::DoubleColon;
    }

    /**
     * One top-level `use` clause (the tokens between `use` and `;`, comments already gone): class imports only.
     *
     * @param Token[] $toks
     * @param array<string, string> $uses alias → FQN
     */
    private static function useClause(array $toks, array &$uses): void
    {
        $prefix = '';
        $cur = '';
        $alias = '';
        $asNext = false;
        $itemKind = false;
        $clauseKind = false;
        $flush = function () use (&$prefix, &$cur, &$alias, &$itemKind, &$clauseKind, &$uses): void {
            if ($cur !== '' && !$itemKind && !$clauseKind) {
                $fqn = $prefix . \ltrim($cur, '\\');
                $uses[$alias !== '' ? $alias : \substr($fqn, (int)\strrpos('\\' . $fqn, '\\'))] = $fqn;
            }
            $cur = '';
            $alias = '';
            $itemKind = false;
        };
        foreach ($toks as $t) {
            $k = $t->kind;
            if ($k === TokenKind::Keyword && ($t->lexeme === 'function' || $t->lexeme === 'const') && $cur === '') {
                if ($prefix === '') { $clauseKind = true; } else { $itemKind = true; }
            } elseif ($k === TokenKind::Keyword && $t->lexeme === 'as') {
                $asNext = true;
            } elseif ($k === TokenKind::Identifier || $k === TokenKind::Keyword) {
                if ($asNext) { $alias = $t->lexeme; $asNext = false; } else { $cur .= $t->lexeme; }
            } elseif ($k === TokenKind::Backslash) {
                $cur .= '\\';
            } elseif ($k === TokenKind::OpenBrace) {
                $prefix = \trim($cur, '\\') . '\\';
                $cur = '';
            } elseif ($k === TokenKind::Comma || $k === TokenKind::CloseBrace) {
                $flush();
            }
        }
        $flush();
    }

    /**
     * @param array<string, int> $declared
     * @param array<int, array{0: string, 1: int, 2: int}> $out
     */
    private static function doc(string $doc, int $line, int $col0, array &$declared, array &$out): void
    {
        $lines = \explode("\n", $doc);
        $last = \count($lines) - 1;
        $tag = '';
        $body = '';
        /** @var array<int, array{0: int, 1: int, 2: int}> $segs [body index, line, 1-based column] */
        $segs = [];
        foreach ($lines as $i => $ln) {
            $len = \strlen($ln);
            $end = $len;
            if ($i === $last && $len >= 2 && \substr($ln, $len - 2) === '*/') { $end = $len - 2; }
            while ($end > 0 && ($ln[$end - 1] === ' ' || $ln[$end - 1] === "\t" || $ln[$end - 1] === "\r")) { $end = $end - 1; }
            $j = $i === 0 ? 3 : 0;
            while ($j < $end && ($ln[$j] === ' ' || $ln[$j] === "\t" || $ln[$j] === '*')) { $j = $j + 1; }
            $base = $i === 0 ? $col0 : 0;
            if ($j < $end && $ln[$j] === '@') {
                if ($tag !== '') { self::tag($tag, $body, $segs, $declared, $out); }
                $k = $j + 1;
                while ($k < $end && (\ctype_alnum($ln[$k]) || $ln[$k] === '-' || $ln[$k] === '_')) { $k = $k + 1; }
                $tag = \strtolower(\substr($ln, $j + 1, $k - $j - 1));
                $body = \substr($ln, $k, $end - $k);
                $segs = [[0, $line + $i, $base + $k + 1]];
            } elseif ($tag !== '' && $j < $end) {
                $segs[] = [\strlen($body) + 1, $line + $i, $base + $j + 1];
                $body = $body . "\n" . \substr($ln, $j, $end - $j);
            }
        }
        if ($tag !== '') { self::tag($tag, $body, $segs, $declared, $out); }
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: int}> $segs
     * @param array<string, int> $declared
     * @param array<int, array{0: string, 1: int, 2: int}> $out
     */
    private static function tag(string $tag, string $body, array $segs, array &$declared, array &$out): void
    {
        $t = $tag;
        foreach (['psalm-', 'phpstan-', 'phan-'] as $p) {
            if (\strpos($t, $p) === 0) { $t = \substr($t, \strlen($p)); break; }
        }
        $n = \strlen($body);
        $i = self::skipWs($body, 0, $n);
        if ($t === 'type') {
            if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', \substr($body, $i), $mm) === 1) { $declared[$mm[0]] = 1; }
            return;
        }
        if (\strpos($t, 'template') === 0 && $t !== 'template-extends' && $t !== 'template-implements' && $t !== 'template-use') {
            $j = self::ident($body, $i, $n);
            if ($j === $i) { return; }
            $declared[\substr($body, $i, $j - $i)] = 1;
            $k = self::skipWs($body, $j, $n);
            $w = self::ident($body, $k, $n);
            $word = \strtolower(\substr($body, $k, $w - $k));
            if ($word !== 'of' && $word !== 'as' && $word !== 'super') { return; }
            $k = self::skipWs($body, $w, $n);
            self::names($body, $k, self::extent($body, $k, $n), $segs, $declared, $out);
            return;
        }
        if ($t === 'method') {
            self::method($body, $i, $n, $segs, $declared, $out);
            return;
        }
        if (isset(self::TYPE_TAGS[$t])) {
            $e = self::extent($body, $i, $n);
            if (($t === 'return' || $t === 'throws') && self::isProse($body, $i, $e, $n)) { return; }
            self::names($body, $i, $e, $segs, $declared, $out);
        }
    }

    /**
     * `[static] [ReturnType] name(Type $a, ...)`: the return type and each parameter type.
     *
     * @param array<int, array{0: int, 1: int, 2: int}> $segs
     * @param array<string, int> $declared
     * @param array<int, array{0: string, 1: int, 2: int}> $out
     */
    private static function method(string $b, int $i, int $n, array $segs, array &$declared, array &$out): void
    {
        $p = \strpos($b, '(', $i);
        if ($p === false) { return; }
        $j = self::ident($b, $i, $p);
        if (\strtolower(\substr($b, $i, $j - $i)) === 'static') {
            $k = self::skipWs($b, $j, $p);
            if ($k > $j && $k < $p) { $i = $k; }
        }
        $e = self::extent($b, $i, $p);
        $after = self::skipWs($b, $e, $p);
        if ($after < $p) { self::names($b, $i, $e, $segs, $declared, $out); }
        $depth = 0;
        $q = $p + 1;
        $from = $q;
        while ($q <= $n) {
            $c = $q < $n ? $b[$q] : ')';
            if ($c === '(' || $c === '<' || $c === '{' || $c === '[') { $depth++; }
            elseif (($c === ')' || $c === '>' || $c === '}' || $c === ']') && $depth > 0) { $depth--; }
            elseif (($c === ',' && $depth === 0) || ($c === ')' && $depth === 0)) {
                $s = self::skipWs($b, $from, $q);
                if ($s < $q && $b[$s] !== '$' && $b[$s] !== '&' && $b[$s] !== '.') {
                    self::names($b, $s, self::extent($b, $s, $q), $segs, $declared, $out);
                }
                $from = $q + 1;
                if ($c === ')') { break; }
            }
            $q++;
        }
    }

    /** `@return the thing` / `@return A new instance`: a lone lowercase word or an article-like opener with more words after it is description, not a type. */
    private static function isProse(string $s, int $i, int $e, int $n): bool
    {
        $w = \substr($s, $i, $e - $i);
        if (\preg_match('/^[a-z][a-z0-9_]*$/', $w) !== 1 && !isset(self::PROSE_STARTERS[\strtolower($w)])) { return false; }
        $k = self::skipWs($s, $e, $n);
        return $k > $e && $k < $n && (\ctype_alnum($s[$k]) || $s[$k] === '_');
    }

    /** End of the type expression starting at $i: stops at whitespace outside brackets unless a `|` / `&` joins the next part. */
    private static function extent(string $s, int $i, int $n): int
    {
        $depth = 0;
        $j = $i;
        while ($j < $n) {
            $c = $s[$j];
            if ($c === "'" || $c === '"') {
                $e = \strpos($s, $c, $j + 1);
                $j = $e === false ? $n : $e + 1;
                continue;
            }
            if ($c === '<' || $c === '(' || $c === '{' || $c === '[') { $depth++; }
            elseif ($c === '>' || $c === ')' || $c === '}' || $c === ']') {
                if ($depth === 0) { break; }
                $depth--;
            } elseif ($depth === 0 && ($c === ' ' || $c === "\t" || $c === "\n")) {
                $k = self::skipWs($s, $j, $n);
                $nx = $k < $n ? $s[$k] : '';
                $pv = $j > $i ? $s[$j - 1] : '';
                $join = $nx === '|' || $nx === '&' || $pv === '|' || $pv === '&' || ($pv === ':' && $j >= $i + 2 && $s[$j - 2] === ')');
                if (!$join) { break; }
                $j = $k;
                continue;
            }
            $j++;
        }
        return $j;
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: int}> $segs
     * @param array<string, int> $declared
     * @param array<int, array{0: string, 1: int, 2: int}> $out
     */
    private static function names(string $s, int $from, int $to, array $segs, array $declared, array &$out): void
    {
        $i = $from;
        while ($i < $to) {
            $c = $s[$i];
            if ($c === "'" || $c === '"') {
                $e = \strpos($s, $c, $i + 1);
                $i = $e === false ? $to : $e + 1;
                continue;
            }
            if ($c === '$') {
                $i = self::ident($s, $i + 1, $to);
                continue;
            }
            if (!self::isStart($c)) {
                if ($c >= '0' && $c <= '9') {
                    while ($i < $to && (\ctype_alnum($s[$i]) || $s[$i] === '.' || $s[$i] === '_')) { $i++; }
                } else {
                    $i++;
                }
                continue;
            }
            $j = $i;
            while ($j < $to && (self::isIdent($s[$j]) || ($s[$j] === '-' && $j + 1 < $to && self::isIdent($s[$j + 1])))) { $j++; }
            $tok = \substr($s, $i, $j - $i);
            $k = self::skipWs($s, $j, $to);
            $key = $k < $to && ($s[$k] === ':' || ($s[$k] === '?' && $k + 1 < $to && $s[$k + 1] === ':'));
            $scope = $k + 1 < $to && $s[$k] === ':' && $s[$k + 1] === ':';
            if ($scope) { $key = false; }
            $skip = $key || \strpos($tok, '-') !== false || isset($declared[$tok]) || isset(self::WORDS[\strtolower($tok)]) || DocNames::isKeyword(\strtolower($tok));
            if (!$skip) {
                $at = 0;
                foreach ($segs as $x => $sg) { if ($sg[0] <= $i) { $at = $x; } }
                $out[] = [$tok, $segs[$at][1], $segs[$at][2] + $i - $segs[$at][0]];
            }
            $i = $j;
            if ($scope) {
                $i = $k + 2;
                while ($i < $to && (self::isIdent($s[$i]) || $s[$i] === '*')) { $i++; }
            }
        }
    }

    private static function skipWs(string $s, int $i, int $n): int
    {
        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\n" || $s[$i] === "\r")) { $i++; }
        return $i;
    }

    private static function ident(string $s, int $i, int $n): int
    {
        while ($i < $n && self::isIdent($s[$i]) && $s[$i] !== '\\') { $i++; }
        return $i;
    }

    private static function isStart(string $c): bool
    {
        return self::isIdent($c) && !($c >= '0' && $c <= '9');
    }

    private static function isIdent(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9') || $c === '_' || $c === '\\' || \ord($c) >= 128;
    }
}
