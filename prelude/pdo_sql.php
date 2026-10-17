<?php

/**
 * pdo_sql — code shared by the socket drivers (pdo_pgsql, pdo_mysql): the
 * statement-text rewriting and wire helpers neither driver should own twice.
 * Concatenated AFTER pdo.php and BEFORE the drivers that use it.
 */

/**
 * What {@see __McPdoSql::scan} found. A class, not an array: `sql` is text while
 * `names` / `pos` are lists, and a mixed-value array loses its element channel.
 */
final class __McPdoSqlScan
{
    /** pgsql: the statement with every placeholder rewritten to `$n`; mysql: the input, untouched. */
    public string $sql = '';

    /**
     * One entry per bound slot, in statement order: `'?'` or `':name'`. pgsql
     * numbers a repeated `:name` once (slot = first occurrence); mysql
     * emulation gives every occurrence its own slot.
     * @var array<int,string>
     */
    public array $names = [];

    /**
     * mysql only: byte offset in `sql` of slot i's placeholder
     * (`strlen($names[$i])` bytes long), for splicing in quoted values.
     * @var array<int,int>
     */
    public array $pos = [];
}

final class __McPdoSql
{
    /**
     * The placeholder walk php's PDO SQL parser does, one forward pass.
     * `$dialect` is `'pgsql'` or `'mysql'`; everything below is what the live
     * oracle (php 8.5 pdo_pgsql / pdo_mysql) does, not what the SQL grammar says:
     *  - a quote with no closing partner is a plain character and scanning goes on;
     *    an unterminated `/*` or `$tag$` swallows the rest of the text
     *  - `/* *` / is NOT nested, in either dialect
     *  - `:name` needs `[A-Za-z0-9_]+` and a previous byte that is neither a name
     *    byte nor `:` (so `a:b`, `:a:b`, `::int` bind nothing)
     *  - pgsql: each `??` pair is a literal `?` (an odd leftover is a slot); mysql
     *    keeps a run of two or more `?` as written, no slot
     */
    public static function scan(string $sql, string $dialect): __McPdoSqlScan
    {
        $pg = $dialect === 'pgsql';
        $n = \strlen($sql);
        $r = new __McPdoSqlScan();
        $out = '';
        /** @var array<string,int> */
        $seen = [];
        $slots = 0;
        $named = false;
        $positional = false;
        $run = 0;
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === "'") {
                $esc = !$pg || ($i > 0 && ($sql[$i - 1] === 'e' || $sql[$i - 1] === 'E'));
                $e = self::skipQuoted($sql, $i, "'", $esc);
                $i = $e < 0 ? $i + 1 : $e;
            } elseif ($c === '"') {
                $e = self::skipQuoted($sql, $i, '"', !$pg);
                $i = $e < 0 ? $i + 1 : $e;
            } elseif ($c === '`' && !$pg) {
                $e = self::skipQuoted($sql, $i, '`', false);
                $i = $e < 0 ? $i + 1 : $e;
            } elseif ($c === '$' && $pg) {
                $j = $i + 1;
                if ($j < $n && self::isNameByte(\ord($sql[$j]), false)) {
                    while ($j < $n && self::isNameByte(\ord($sql[$j]), true)) { $j++; }
                }
                if ($j < $n && $sql[$j] === '$') {
                    $tag = \substr($sql, $i, $j - $i + 1);
                    $e = \strpos($sql, $tag, $j + 1);
                    $i = $e === false ? $n : $e + \strlen($tag);
                } else {
                    $i++;
                }
            } elseif ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-'
                      && ($pg || $i + 2 >= $n || \ord($sql[$i + 2]) <= 32)) {
                $e = \strpos($sql, "\n", $i);
                $i = $e === false ? $n : $e;
            } elseif ($c === '#' && !$pg) {
                $e = \strpos($sql, "\n", $i);
                $i = $e === false ? $n : $e;
            } elseif ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
                $e = \strpos($sql, '*/', $i + 2);
                $i = $e === false ? $n : $e + 2;
            } elseif ($c === '?') {
                if ($i + 1 < $n && $sql[$i + 1] === '?') {
                    if ($pg) {
                        $out .= \substr($sql, $run, $i + 1 - $run);
                        $run = $i + 2;
                        $i += 2;
                    } else {
                        while ($i < $n && $sql[$i] === '?') { $i++; }
                    }
                    continue;
                }
                if ($named) { throw self::mixed(); }
                $positional = true;
                $r->names[] = '?';
                $slots++;
                if ($pg) {
                    $out .= \substr($sql, $run, $i - $run) . '$' . (string) $slots;
                    $run = $i + 1;
                } else {
                    $r->pos[] = $i;
                }
                $i++;
            } elseif ($c === ':') {
                if ($i + 1 < $n && $sql[$i + 1] === ':') {
                    $i += 2;
                    continue;
                }
                $j = $i + 1;
                while ($j < $n && self::isNameByte(\ord($sql[$j]), true) && \ord($sql[$j]) < 128) { $j++; }
                if ($j === $i + 1 || ($i > 0 && ($sql[$i - 1] === ':' || (self::isNameByte(\ord($sql[$i - 1]), true)
                                                                         && \ord($sql[$i - 1]) < 128)))) {
                    $i++;
                    continue;
                }
                if ($positional) { throw self::mixed(); }
                $named = true;
                $name = \substr($sql, $i, $j - $i);
                if ($pg) {
                    if (isset($seen[$name])) {
                        $slot = $seen[$name];
                    } else {
                        $r->names[] = $name;
                        $slots++;
                        $slot = $slots;
                        $seen[$name] = $slot;
                    }
                    $out .= \substr($sql, $run, $i - $run) . '$' . (string) $slot;
                    $run = $j;
                } else {
                    $r->names[] = $name;
                    $r->pos[] = $i;
                }
                $i = $j;
            } else {
                $i++;
            }
        }
        $r->sql = $pg ? $out . \substr($sql, $run) : $sql;
        return $r;
    }

    /** The pgsql rewrite: `?` / `:name` → `$1..$n`, a repeated name reusing its number. */
    public static function rewritePg(string $sql): __McPdoSqlScan
    {
        return self::scan($sql, 'pgsql');
    }

    private static function mixed(): PDOException
    {
        return __McPdo::fail('HY093', 0, false, 'mixed named and positional parameters', true);
    }

    /** Name byte: `[A-Za-z0-9_]`; `$digits` false = a leading byte (no digit). High bytes count too. */
    private static function isNameByte(int $o, bool $digits): bool
    {
        return ($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90) || $o === 95 || $o >= 128
            || ($digits && $o >= 48 && $o <= 57);
    }

    /**
     * Index just past the closing `$q` of the literal opening at `$i`, or -1 when
     * it never closes. A doubled `$q` stays inside; `$bs` makes a backslash eat
     * the next byte.
     */
    private static function skipQuoted(string $sql, int $i, string $q, bool $bs): int
    {
        $n = \strlen($sql);
        $j = $i + 1;
        while ($j < $n) {
            $ch = $sql[$j];
            if ($bs && $ch === '\\') {
                $j += 2;
            } elseif ($ch === $q) {
                if ($j + 1 < $n && $sql[$j + 1] === $q) { $j += 2; continue; }
                return $j + 1;
            } else {
                $j++;
            }
        }
        return -1;
    }
}
