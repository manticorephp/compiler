<?php

namespace Semantic;

/**
 * The class names written inside the type-carrying tags of a file's docblocks (`@param Foo $x`, `@return Foo[]`,
 * `@var array<int, Foo>`, `@method`, `@template T of Foo` ...), with their positions and the FQN each resolves to.
 * Prose lines and non-type tags are never read. Docblocks are found by text, skipping strings, plain comments,
 * heredocs and inline HTML; the parser keeps no comment span. The namespace and `use` map in force at each
 * docblock are tracked from the same pass (a file may hold several namespace blocks).
 */
final class DocblockNames
{
    private const TYPE_TAGS = [
        'param' => 1, 'return' => 1, 'var' => 1, 'throws' => 1, 'property' => 1, 'property-read' => 1, 'property-write' => 1,
        'extends' => 1, 'implements' => 1, 'use' => 1, 'mixin' => 1, 'template-extends' => 1, 'template-implements' => 1,
        'template-use' => 1, 'param-out' => 1, 'assert' => 1, 'assert-if-true' => 1, 'assert-if-false' => 1,
        'self-out' => 1, 'this-out' => 1,
    ];

    private const WORDS = ['min' => 1, 'max' => 1, 'is' => 1, 'not' => 1, 'of' => 1, 'as' => 1, 'empty' => 1, 'array' => 1, 'list' => 1];

    private static function tokenRe(): string
    {
        $skip = [
            '\x27(?:[^\x27\\\\]++|\\\\.)*+\x27',
            '\x22(?:[^\x22\\\\]++|\\\\.)*+\x22',
            '//[^\n]*+',
            '#(?!\[)[^\n]*+',
            '/\*\*/',
            '/\*(?!\*)(?:[^*]++|\*(?!/))*+\*/',
            '<<<[ \t]*(?P<q>[\x22\x27]?)(?P<id>[A-Za-z_][A-Za-z0-9_]*)(?P=q)\r?\n(?:.*?\n)?[ \t]*(?P=id)(?![A-Za-z0-9_])',
            '\?>.*?(?=<\?(?:php|=)|\z)',
        ];
        $keep = [
            '(?P<doc>/\*\*(?:[^*]++|\*(?!/))*+\*/)',
            '(?P<ns>(?<![\w$>:\\\\])namespace(?:\s+(?P<nsname>[A-Za-z_\x80-\xff][\w\\\\\x80-\xff]*))?\s*(?P<nsend>[;{]))',
            '(?P<use>(?<![\w$>:\\\\])use\s+(?P<useclause>[^;{}()]*(?:\{[^{}]*\}[^;{}()]*)?);)',
            '(?P<open>\{)',
            '(?P<close>\})',
        ];
        return '~(?:' . \implode('|', $skip) . ')(*SKIP)(*F)|' . \implode('|', $keep) . '~s';
    }

    /**
     * $ns and $uses seed the degraded path only (see scanBlocks); the main path reads them from the text.
     *
     * @param array<string, string> $uses alias → FQN
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}> [written name, line, 1-based byte column, FQN]; a name never spans lines
     */
    public static function scan(string $text, string $ns = '', array $uses = []): array
    {
        $out = [];
        if (\strpos($text, '/**') === false) { return $out; }
        $n = \preg_match_all(self::tokenRe(), $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($n === false || \preg_last_error() !== PREG_NO_ERROR) { return self::scanBlocks($text, $ns, $uses); }
        /** @var array<string, int> $declared template and type-alias names seen so far in the file */
        $declared = [];
        /** @var array<string, string> $curUses */
        $curUses = [];
        $curNs = '';
        $depth = 0;
        $base = 0;
        $braced = false;
        $line = 1;
        $prev = 0;
        $lineStart = 0;
        foreach ($m as $hit) {
            if (isset($hit['doc']) && $hit['doc'][1] >= 0) {
                $off = $hit['doc'][1];
                $seg = \substr($text, $prev, $off - $prev);
                $nl = \substr_count($seg, "\n");
                if ($nl > 0) {
                    $line = $line + $nl;
                    $lineStart = $prev + \strrpos($seg, "\n") + 1;
                }
                $prev = $off;
                if (\strpos($hit['doc'][0], '@') === false) { continue; }
                $before = \count($out);
                self::doc($hit['doc'][0], $line, $off - $lineStart, $declared, $out);
                for ($k = $before; $k < \count($out); $k++) { $out[$k][] = DocNames::qualify($out[$k][0], $curNs, $curUses); }
            } elseif (isset($hit['ns']) && $hit['ns'][1] >= 0) {
                $curNs = isset($hit['nsname']) ? $hit['nsname'][0] : '';
                $curUses = [];
                if ($hit['nsend'][0] === '{') {
                    $depth++;
                    $braced = true;
                    $base = $depth;
                }
            } elseif (isset($hit['use']) && $hit['use'][1] >= 0) {
                if ($depth === ($braced ? $base : 0)) { self::useClause($hit['useclause'][0], $curUses); }
            } elseif (isset($hit['open']) && $hit['open'][1] >= 0) {
                $depth++;
            } elseif (isset($hit['close']) && $hit['close'][1] >= 0) {
                $depth--;
                if ($braced && $depth < $base) {
                    $curNs = '';
                    $curUses = [];
                    $braced = false;
                    $base = 0;
                }
            }
        }
        return $out;
    }

    /**
     * Degraded path when the PCRE scan fails: every `/** ... *\/` block by plain search, resolved against one
     * namespace and use map. Strings and comments are not told apart, so a file with more than one `namespace`
     * (or a lookalike) yields nothing rather than a possibly wrong class.
     *
     * @param array<string, string> $uses
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function scanBlocks(string $text, string $ns, array $uses): array
    {
        $out = [];
        if (\preg_match_all('/(?<![\w$>:\\\\])namespace\s*[A-Za-z_{]/', $text) > 1) { return $out; }
        $declared = [];
        $line = 1;
        $prev = 0;
        $lineStart = 0;
        $off = 0;
        while (($off = \strpos($text, '/**', $off)) !== false) {
            $e = \strpos($text, '*/', $off + 3);
            $end = $e === false ? \strlen($text) : $e + 2;
            $seg = \substr($text, $prev, $off - $prev);
            $nl = \substr_count($seg, "\n");
            if ($nl > 0) {
                $line = $line + $nl;
                $lineStart = $prev + \strrpos($seg, "\n") + 1;
            }
            $prev = $off;
            $doc = \substr($text, $off, $end - $off);
            if (\strpos($doc, '@') !== false) {
                $before = \count($out);
                self::doc($doc, $line, $off - $lineStart, $declared, $out);
                for ($k = $before; $k < \count($out); $k++) { $out[$k][] = DocNames::qualify($out[$k][0], $ns, $uses); }
            }
            $off = $end;
        }
        return $out;
    }

    /**
     * One top-level `use` clause (the text between `use` and `;`): class imports only.
     *
     * @param array<string, string> $uses alias → FQN
     */
    private static function useClause(string $clause, array &$uses): void
    {
        $clause = \trim($clause);
        if (\preg_match('/^(?:function|const)\s/i', $clause) === 1) { return; }
        $prefix = '';
        $items = [];
        $br = \strpos($clause, '{');
        if ($br === false) {
            $items = \explode(',', $clause);
        } else {
            $prefix = \ltrim(\trim(\substr($clause, 0, $br)), '\\');
            $close = \strrpos($clause, '}');
            $items = \explode(',', \substr($clause, $br + 1, ($close === false ? \strlen($clause) : $close) - $br - 1));
        }
        foreach ($items as $item) {
            if (\preg_match('/^\s*(?:(function|const)\s+)?\\\\?([\w\\\\\x80-\xff]+)(?:\s+as\s+([\w\x80-\xff]+))?\s*$/i', $item, $mm) !== 1) { continue; }
            if ($mm[1] !== '') { continue; }
            $fqn = $prefix . $mm[2];
            $alias = isset($mm[3]) && $mm[3] !== '' ? $mm[3] : \substr($fqn, (int)\strrpos('\\' . $fqn, '\\'));
            $uses[$alias] = $fqn;
        }
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

    /** `@return the thing`: a lone lowercase word with more words after it is description, not a type. */
    private static function isProse(string $s, int $i, int $e, int $n): bool
    {
        if (\preg_match('/^[a-z][a-z0-9_]*$/', \substr($s, $i, $e - $i)) !== 1) { return false; }
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
