<?php

/**
 * "Natural order" string comparison — PHP's strnatcmp family, a port of the
 * Martin Pool natcmp that php-src itself uses (ext/standard/string.c,
 * `strnatcmp_ex`). A run of digits compares as a NUMBER, so "img12" sorts after
 * "img2" where a byte-wise strcmp puts it first.
 *
 * Two digit-run modes, and which one applies is decided by a LEADING ZERO:
 *   · no leading zero → `compare_right`: the longer run of digits is the bigger
 *     number, and only when the runs are the same length does the first
 *     differing digit decide ("125" > "34").
 *   · a leading zero on either side → `compare_left`: the run reads as a
 *     FRACTION, so the first differing digit decides outright ("0.05" < "0.5").
 *
 * Pure-PHP / global namespace; compiled into lib/manticore_stdlib.o and exposed
 * through the .o.sig like every other stdlib function.
 */

/** ASCII digit test — `ctype_digit` semantics on a single byte value. */
function __mc_nat_isdigit(int $c): bool
{
    return $c >= 48 && $c <= 57;
}

/** ASCII whitespace: space, \t, \n, \v, \f, \r — matching C's isspace(). */
function __mc_nat_isspace(int $c): bool
{
    return $c === 32 || ($c >= 9 && $c <= 13);
}

/** ASCII upper-casing of one byte, for the fold-case variants. */
function __mc_nat_upper(int $c): int
{
    if ($c >= 97 && $c <= 122) { return $c - 32; }
    return $c;
}

/**
 * Compare two runs of digits as left-aligned FRACTIONS: the first differing
 * digit wins, and a run that ends first is the smaller one. Advances both
 * cursors past the runs.
 */
function __mc_nat_cmp_left(string $a, int &$ap, int $alen, string $b, int &$bp, int $blen): int
{
    while (true) {
        $da = $ap < $alen && __mc_nat_isdigit(\ord($a[$ap]));
        $db = $bp < $blen && __mc_nat_isdigit(\ord($b[$bp]));
        if (!$da && !$db) { return 0; }
        if (!$da) { return -1; }
        if (!$db) { return 1; }
        $ca = \ord($a[$ap]);
        $cb = \ord($b[$bp]);
        if ($ca < $cb) { return -1; }
        if ($ca > $cb) { return 1; }
        $ap = $ap + 1;
        $bp = $bp + 1;
    }
}

/**
 * Compare two runs of digits as INTEGERS: the longer run is the bigger number,
 * so the first difference is only remembered (`bias`) and applied once both runs
 * end together. Advances both cursors past the runs.
 */
function __mc_nat_cmp_right(string $a, int &$ap, int $alen, string $b, int &$bp, int $blen): int
{
    $bias = 0;
    while (true) {
        $da = $ap < $alen && __mc_nat_isdigit(\ord($a[$ap]));
        $db = $bp < $blen && __mc_nat_isdigit(\ord($b[$bp]));
        if (!$da && !$db) { return $bias; }
        if (!$da) { return -1; }
        if (!$db) { return 1; }
        $ca = \ord($a[$ap]);
        $cb = \ord($b[$bp]);
        if ($ca < $cb) { if ($bias === 0) { $bias = -1; } }
        elseif ($ca > $cb) { if ($bias === 0) { $bias = 1; } }
        $ap = $ap + 1;
        $bp = $bp + 1;
    }
}

/** The shared engine; `$foldCase` gives the strnatcasecmp variants. */
function __mc_natcmp(string $a, string $b, bool $foldCase): int
{
    $alen = \strlen($a);
    $blen = \strlen($b);
    if ($alen === 0 || $blen === 0) {
        if ($alen === $blen) { return 0; }
        return $alen > $blen ? 1 : -1;
    }
    $ap = 0;
    $bp = 0;
    $leading = true;
    while (true) {
        $ca = $ap < $alen ? \ord($a[$ap]) : 0;
        $cb = $bp < $blen ? \ord($b[$bp]) : 0;

        // Leading zeros are not part of the number: "007" reads as "7". Only
        // skipped while still at the head of the string.
        while ($leading && $ca === 48 && ($ap + 1) < $alen && __mc_nat_isdigit(\ord($a[$ap + 1]))) {
            $ap = $ap + 1;
            $ca = \ord($a[$ap]);
        }
        while ($leading && $cb === 48 && ($bp + 1) < $blen && __mc_nat_isdigit(\ord($b[$bp + 1]))) {
            $bp = $bp + 1;
            $cb = \ord($b[$bp]);
        }
        $leading = false;

        while (__mc_nat_isspace($ca)) {
            $ap = $ap + 1;
            $ca = $ap < $alen ? \ord($a[$ap]) : 0;
        }
        while (__mc_nat_isspace($cb)) {
            $bp = $bp + 1;
            $cb = $bp < $blen ? \ord($b[$bp]) : 0;
        }

        if (__mc_nat_isdigit($ca) && __mc_nat_isdigit($cb)) {
            $fractional = ($ca === 48 || $cb === 48);
            if ($fractional) {
                $result = __mc_nat_cmp_left($a, $ap, $alen, $b, $bp, $blen);
            } else {
                $result = __mc_nat_cmp_right($a, $ap, $alen, $b, $bp, $blen);
            }
            if ($result !== 0) { return $result; }
            if ($ap >= $alen && $bp >= $blen) { return 0; }
            if ($ap >= $alen) { return -1; }
            if ($bp >= $blen) { return 1; }
            $ca = \ord($a[$ap]);
            $cb = \ord($b[$bp]);
        }

        if ($foldCase) {
            $ca = __mc_nat_upper($ca);
            $cb = __mc_nat_upper($cb);
        }
        if ($ca < $cb) { return -1; }
        if ($ca > $cb) { return 1; }

        $ap = $ap + 1;
        $bp = $bp + 1;
        if ($ap >= $alen && $bp >= $blen) { return 0; }
        if ($ap >= $alen) { return -1; }
        if ($bp >= $blen) { return 1; }
    }
}

/** `strnatcmp(a, b)` — natural-order comparison, case sensitive. -1 / 0 / 1. */
function strnatcmp(string $string1, string $string2): int
{
    return __mc_natcmp($string1, $string2, false);
}

/** `strnatcasecmp(a, b)` — natural-order comparison, case INsensitive. */
function strnatcasecmp(string $string1, string $string2): int
{
    return __mc_natcmp($string1, $string2, true);
}

/**
 * `natsort(&$array)` — sort in natural order, PRESERVING the key association.
 * Delegates to uasort so the by-ref array plumbing (and its rc discipline) stays
 * in one battle-tested place rather than a second hand-rolled sort here.
 */
function natsort(array &$array): bool
{
    return \uasort($array, 'strnatcmp');
}

/** `natcasesort(&$array)` — natsort, case insensitive. */
function natcasesort(array &$array): bool
{
    return \uasort($array, 'strnatcasecmp');
}
