<?php

namespace Semantic;

/**
 * Hover rendering of a {@see SourceType}: one readable line (`inline`) and, for array-like types, a short markdown
 * bullet list of keys and values (`detail`). Pure.
 *
 * `array<V>` (one argument, as written `V[]`) renders as `V[]`; `list<V>` and `array<K, V>` render as written.
 */
final class TypeDetail
{
    public const MAX_LINES = 12;
    private const MAX_DEPTH = 3;

    public static function inline(SourceType $t): string
    {
        switch ($t->kind) {
            case SourceType::NULLABLE:
                return '?' . self::inline($t->args[0]);
            case SourceType::UNION:
                $parts = [];
                foreach ($t->args as $a) {
                    $s = self::inline($a);
                    $parts[] = $a->kind === SourceType::INTERSECTION ? '(' . $s . ')' : $s;
                }
                return \implode('|', $parts);
            case SourceType::INTERSECTION:
                $parts = [];
                foreach ($t->args as $a) { $parts[] = self::inline($a); }
                return \implode('&', $parts);
            case SourceType::GENERIC:
            case SourceType::ITERABLE:
                if ($t->args === []) { return $t->name; }
                if ($t->name === 'array' && \count($t->args) === 1) {
                    $v = $t->args[0];
                    $s = self::inline($v);
                    $wrap = $v->kind === SourceType::UNION || $v->kind === SourceType::NULLABLE
                        || $v->kind === SourceType::INTERSECTION || $v->kind === SourceType::CALLABLE;
                    return ($wrap ? '(' . $s . ')' : $s) . '[]';
                }
                $parts = [];
                foreach ($t->args as $a) { $parts[] = self::inline($a); }
                return $t->name . '<' . \implode(', ', $parts) . '>';
            case SourceType::SHAPE:
                $parts = [];
                foreach ($t->args as $i => $a) { $parts[] = self::keyText($t->keys[$i]) . ': ' . self::inline($a); }
                return $t->name . '{' . \implode(', ', $parts) . '}';
            case SourceType::LITERAL:
                return (string)\preg_replace('/[\x00-\x1f]/', ' ', $t->name);
            default:
                return $t->toString();
        }
    }

    /** A shape key as written: bare when it is a plain word, else single-quoted with `\`, `'`, backtick and control characters escaped. */
    public static function keyText(string $key): string
    {
        $opt = \str_ends_with($key, '?');
        $k = $opt ? \substr($key, 0, -1) : $key;
        $cut = \strlen($k) > 60;
        if ($cut) {
            $len = 60;
            while ($len > 0 && (\ord($k[$len]) & 0xC0) === 0x80) { $len = $len - 1; }
            $k = \substr($k, 0, $len);
        }
        if (\preg_match('/^[A-Za-z0-9_\x80-\xff]+$/', $k) !== 1) {
            $out = '';
            $n = \strlen($k);
            for ($i = 0; $i < $n; $i = $i + 1) {
                $ch = $k[$i];
                if ($ch === '\\' || $ch === "'" || $ch === '`') { $out .= '\\' . $ch; }
                elseif ($ch === "\n") { $out .= '\\n'; }
                elseif ($ch === "\r") { $out .= '\\r'; }
                elseif (\ord($ch) < 32 || \ord($ch) === 127) { $out .= '\\x' . \sprintf('%02x', \ord($ch)); }
                else { $out .= $ch; }
            }
            $k = "'" . $out . ($cut ? '…' : '') . "'";
        } elseif ($cut) {
            $k = $k . '…';
        }
        return $k . ($opt ? '?' : '');
    }

    /** A markdown code span that survives backticks in its text (delimiter longer than the longest run inside). */
    public static function span(string $text): string
    {
        $run = 0;
        if (\preg_match_all('/`+/', $text, $m) > 0) {
            foreach ($m[0] as $r) { if (\strlen($r) > $run) { $run = \strlen($r); } }
        }
        $d = \str_repeat('`', $run + 1);
        $pad = $run > 0 ? ' ' : '';
        return $d . $pad . $text . $pad . $d;
    }

    /** The array part of a type: itself, the inner type of a nullable, or the single array arm of a union. */
    public static function arrayPart(SourceType $t): ?SourceType
    {
        if (self::isArrayLike($t)) { return $t; }
        if ($t->kind === SourceType::NULLABLE) { return self::arrayPart($t->args[0]); }
        if ($t->kind !== SourceType::UNION) { return null; }
        $found = null;
        foreach ($t->args as $a) {
            if (!self::isArrayLike($a)) { continue; }
            if ($found !== null) { return null; }
            $found = $a;
        }
        return $found;
    }

    /** Markdown bullet list of keys and values for an array-like type; '' for any other type. */
    public static function detail(SourceType $t): string
    {
        $a = self::arrayPart($t);
        if ($a === null) { return ''; }
        $lines = [];
        self::lines($a, 0, $lines);
        if (\count($lines) > self::MAX_LINES) {
            $lines = \array_slice($lines, 0, self::MAX_LINES - 1);
            $lines[] = '…';
        }
        return \implode("\n", $lines);
    }

    private static function isArrayLike(SourceType $t): bool
    {
        if ($t->kind === SourceType::SHAPE || $t->kind === SourceType::ITERABLE) { return true; }
        if ($t->kind === SourceType::SCALAR) { return $t->name === 'array'; }
        return $t->kind === SourceType::GENERIC && ($t->name === 'array' || $t->name === 'list');
    }

    /**
     * @param string[] $lines
     */
    private static function lines(SourceType $t, int $depth, array &$lines): void
    {
        $pad = \str_repeat('  ', $depth);
        if ($t->kind === SourceType::SHAPE) {
            foreach ($t->args as $i => $v) {
                $lines[] = $pad . '- ' . self::span(self::keyText($t->keys[$i]) . ': ' . self::inline($v));
                self::nested($v, $depth, $lines);
            }
            return;
        }
        $n = \count($t->args);
        if ($n === 0) {
            $lines[] = $pad . '- keys: ' . self::span($t->name === 'list' ? 'int' : 'int|string');
            $lines[] = $pad . '- values: `mixed`';
            return;
        }
        $v = $t->args[$n - 1];
        $k = $n >= 2 ? self::inline($t->args[0]) : ($t->name === 'list' ? 'int' : 'int|string');
        $lines[] = $pad . '- keys: ' . self::span($k) . ($t->name === 'list' ? ' (list)' : '');
        $lines[] = $pad . '- values: ' . self::span(self::inline($v));
        self::nested($v, $depth, $lines);
    }

    /**
     * @param string[] $lines
     */
    private static function nested(SourceType $v, int $depth, array &$lines): void
    {
        $inner = self::arrayPart($v);
        if ($inner === null || $depth + 1 >= self::MAX_DEPTH || \count($lines) >= self::MAX_LINES) { return; }
        self::lines($inner, $depth + 1, $lines);
    }
}
