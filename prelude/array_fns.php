<?php

/**
 * Callback / element-typed array functions, injected as a PRELUDE (compiled
 * WITH the user program) when the program references them — see Main.php gating
 * and LowerFromAst::$arrayFnsSrc.
 *
 * They can NOT live in the separately-linked stdlib `.o`: there the bare-`array`
 * param's element erases to unknown, so a dynamic callback would receive a raw
 * value while an untyped closure param is a cell (a tag-dispatch on raw bits
 * crashes), and a typed `>` / strcmp can't read a boxed element. Compiled with
 * the user program, call-site element inference types the `array` param and the
 * in-module closure ABI matches.
 *
 * The sorts mutate the by-ref `$arr` IN PLACE by integer index (no
 * array_values, which would re-box elements into a vec[cell]). They assume a
 * list input (the dominant usort/sort case); insertion sort keeps it small-N
 * friendly. sort/rsort order with `>` / `<` (int numeric, string via strcmp).
 *
 * Multi-type call groups are handled by monomorphization (Passes\Monomorphize):
 * `array_map` over int[] in one place and string[] in another specializes into
 * `array_map$mono$p1_vec_int` / `$p1_vec_str`, each with a concrete element type
 * (no erasure, no cell), so the worklist + closure ABI render every element type
 * correctly. A single-type call group keeps the all-agree-inferred original.
 *
 * KNOWN LIMITS (shared by every prelude callback fn, NOT specific to these):
 *  - A string function-name callable (`array_map('strtoupper', ...)`) isn't
 *    resolved — pass a closure / `fn`.
 *  - array_filter does NOT preserve original keys for a sparse result (it
 *    reindexes); use after-the-fact array_values semantics only.
 */

/**
 * Sum a numeric list. Lives in the PRELUDE (not stdlib .o) so call-site
 * element inference / monomorphization types `$a` concretely: over int[] the
 * accumulator stays an i64, over float[] the foreach value is a native double
 * and the loop-carried `$sum` WIDENS int→float (InferTypes::loopMerge) — the
 * addition is INLINE (no closure), so a float sum never round-trips through a
 * cell box (which would truncate the mantissa). A `0` seed keeps an all-int sum
 * integral; PHP promotes to float on the first float element.
 *
 * @param int[]|float[] $a
 */
function array_sum(array $a): int|float
{
    $sum = 0;
    foreach ($a as $v) { $sum = $sum + $v; }
    return $sum;
}

function array_product(array $a): int|float
{
    $p = 1;
    foreach ($a as $v) { $p = $p * $v; }
    return $p;
}

/**
 * `array_flip(a)` — swap keys and values. PRELUDE so call-site inference types
 * `$a` concretely (the values become keys, so they must carry a real int/string
 * type — an erased stdlib `array` would store them raw and misbox the new keys).
 */
function array_flip(array $a): array
{
    $out = [];
    foreach ($a as $k => $v) { $out[$v] = $k; }
    return $out;
}

/**
 * `str_split(s [, length])` — split `$s` into chunks of `$length` bytes (1 by
 * default). PRELUDE (like explode): each chunk is a `substr`, so the return
 * narrows to vec[string] compiled with the program; an erased stdlib `array`
 * return would box-tag each chunk into a vec[cell].
 */
function str_split(string $s, int $length = 1): array
{
    if ($length < 1) { $length = 1; }
    $out = [];
    $n = \strlen($s);
    if ($n === 0) { $out[] = ""; return $out; }
    $i = 0;
    while ($i < $n) {
        $out[] = \substr($s, $i, $length);
        $i = $i + $length;
    }
    return $out;
}

function array_reduce(array $a, callable $callback, mixed $initial = null): mixed
{
    $carry = $initial;
    foreach ($a as $v) {
        $carry = $callback($carry, $v);
    }
    return $carry;
}

function array_map(?callable $cb, array $arr): array
{
    $out = [];
    if ($cb === null) {
        foreach ($arr as $k => $v) { $out[$k] = $v; }
        return $out;
    }
    foreach ($arr as $k => $v) {
        $out[$k] = $cb($v);
    }
    return $out;
}

function array_filter(array $arr, ?callable $cb = null): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $keep = $cb === null ? (bool)$v : (bool)$cb($v);
        if ($keep) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * Bottom-up merge sort — O(n log n), STABLE (matches PHP 8+), and MOVE-BASED so
 * it is refcount-safe for string elements. A `$t`-temp 3-way swap (quicksort/
 * heapsort-with-swap) leaves `$t` dangling once the source slot is overwritten
 * on an rc'd string (int elements are inline, so a swap only corrupted strings);
 * merge only ever COPIES a value from a source slot into a destination slot
 * (`$dst[$k] = $src[$j]`), exactly the rc pattern the element store already
 * handles. A single typed aux buffer (`$tmp`, a vec[T] — no array_values
 * re-boxing to vec[cell]); successive passes ping-pong arr<->tmp and a final
 * copy-back lands the result in `$arr` when an odd number of passes ran. Faster
 * than the prior heapsort (sequential access, ~half the comparisons). Inlined
 * per function — only the comparison direction / callback differs.
 */
function usort(array &$arr, callable $cmp): bool
{
    $n = count($arr);
    if ($n < 2) { return true; }
    $tmp = [];
    for ($i = 0; $i < $n; $i = $i + 1) { $tmp[] = $arr[$i]; }
    $width = 1;
    $inArr = true;
    while ($width < $n) {
        $i = 0;
        while ($i < $n) {
            $mid = $i + $width; if ($mid > $n) { $mid = $n; }
            $hi = $i + 2 * $width; if ($hi > $n) { $hi = $n; }
            $l = $i; $r = $mid; $k = $i;
            if ($inArr) {
                while ($l < $mid && $r < $hi) { if ($cmp($arr[$l], $arr[$r]) <= 0) { $tmp[$k] = $arr[$l]; $l = $l + 1; } else { $tmp[$k] = $arr[$r]; $r = $r + 1; } $k = $k + 1; }
                while ($l < $mid) { $tmp[$k] = $arr[$l]; $l = $l + 1; $k = $k + 1; }
                while ($r < $hi)  { $tmp[$k] = $arr[$r]; $r = $r + 1; $k = $k + 1; }
            } else {
                while ($l < $mid && $r < $hi) { if ($cmp($tmp[$l], $tmp[$r]) <= 0) { $arr[$k] = $tmp[$l]; $l = $l + 1; } else { $arr[$k] = $tmp[$r]; $r = $r + 1; } $k = $k + 1; }
                while ($l < $mid) { $arr[$k] = $tmp[$l]; $l = $l + 1; $k = $k + 1; }
                while ($r < $hi)  { $arr[$k] = $tmp[$r]; $r = $r + 1; $k = $k + 1; }
            }
            $i = $i + 2 * $width;
        }
        $width = $width * 2;
        $inArr = !$inArr;
    }
    if (!$inArr) { for ($i = 0; $i < $n; $i = $i + 1) { $arr[$i] = $tmp[$i]; } }
    return true;
}

function sort(array &$arr): bool
{
    $n = count($arr);
    if ($n < 2) { return true; }
    $tmp = [];
    for ($i = 0; $i < $n; $i = $i + 1) { $tmp[] = $arr[$i]; }
    $width = 1;
    $inArr = true;
    while ($width < $n) {
        $i = 0;
        while ($i < $n) {
            $mid = $i + $width; if ($mid > $n) { $mid = $n; }
            $hi = $i + 2 * $width; if ($hi > $n) { $hi = $n; }
            $l = $i; $r = $mid; $k = $i;
            if ($inArr) {
                while ($l < $mid && $r < $hi) { if ($arr[$l] <= $arr[$r]) { $tmp[$k] = $arr[$l]; $l = $l + 1; } else { $tmp[$k] = $arr[$r]; $r = $r + 1; } $k = $k + 1; }
                while ($l < $mid) { $tmp[$k] = $arr[$l]; $l = $l + 1; $k = $k + 1; }
                while ($r < $hi)  { $tmp[$k] = $arr[$r]; $r = $r + 1; $k = $k + 1; }
            } else {
                while ($l < $mid && $r < $hi) { if ($tmp[$l] <= $tmp[$r]) { $arr[$k] = $tmp[$l]; $l = $l + 1; } else { $arr[$k] = $tmp[$r]; $r = $r + 1; } $k = $k + 1; }
                while ($l < $mid) { $arr[$k] = $tmp[$l]; $l = $l + 1; $k = $k + 1; }
                while ($r < $hi)  { $arr[$k] = $tmp[$r]; $r = $r + 1; $k = $k + 1; }
            }
            $i = $i + 2 * $width;
        }
        $width = $width * 2;
        $inArr = !$inArr;
    }
    if (!$inArr) { for ($i = 0; $i < $n; $i = $i + 1) { $arr[$i] = $tmp[$i]; } }
    return true;
}

function rsort(array &$arr): bool
{
    $n = count($arr);
    if ($n < 2) { return true; }
    $tmp = [];
    for ($i = 0; $i < $n; $i = $i + 1) { $tmp[] = $arr[$i]; }
    $width = 1;
    $inArr = true;
    while ($width < $n) {
        $i = 0;
        while ($i < $n) {
            $mid = $i + $width; if ($mid > $n) { $mid = $n; }
            $hi = $i + 2 * $width; if ($hi > $n) { $hi = $n; }
            $l = $i; $r = $mid; $k = $i;
            if ($inArr) {
                while ($l < $mid && $r < $hi) { if ($arr[$l] >= $arr[$r]) { $tmp[$k] = $arr[$l]; $l = $l + 1; } else { $tmp[$k] = $arr[$r]; $r = $r + 1; } $k = $k + 1; }
                while ($l < $mid) { $tmp[$k] = $arr[$l]; $l = $l + 1; $k = $k + 1; }
                while ($r < $hi)  { $tmp[$k] = $arr[$r]; $r = $r + 1; $k = $k + 1; }
            } else {
                while ($l < $mid && $r < $hi) { if ($tmp[$l] >= $tmp[$r]) { $arr[$k] = $tmp[$l]; $l = $l + 1; } else { $arr[$k] = $tmp[$r]; $r = $r + 1; } $k = $k + 1; }
                while ($l < $mid) { $arr[$k] = $tmp[$l]; $l = $l + 1; $k = $k + 1; }
                while ($r < $hi)  { $arr[$k] = $tmp[$r]; $r = $r + 1; $k = $k + 1; }
            }
            $i = $i + 2 * $width;
        }
        $width = $width * 2;
        $inArr = !$inArr;
    }
    if (!$inArr) { for ($i = 0; $i < $n; $i = $i + 1) { $arr[$i] = $tmp[$i]; } }
    return true;
}

/**
 * `ksort` / `krsort` — sort by KEY (ascending / descending), preserving the
 * key=>value association. Self-contained insertion sort over the KEY vector;
 * `array_keys` returns NaN-boxed cell keys, so `$keys[$j] > $kk` dispatches by
 * tag (string→strcmp, int→numeric) via __manticore_tagged_compare — correct for
 * both string and int keys. Does NOT call the shared sort() (that would add a
 * conflicting call site and erase sort's element type).
 */
function ksort(array &$arr): bool
{
    $keys = array_keys($arr);
    $n = count($keys);
    for ($i = 1; $i < $n; $i = $i + 1) {
        $kk = $keys[$i];
        $j = $i - 1;
        while ($j >= 0 && $keys[$j] > $kk) { $keys[$j + 1] = $keys[$j]; $j = $j - 1; }
        $keys[$j + 1] = $kk;
    }
    $new = [];
    foreach ($keys as $k) { $new[$k] = $arr[$k]; }
    $arr = $new;
    return true;
}

function krsort(array &$arr): bool
{
    $keys = array_keys($arr);
    $n = count($keys);
    for ($i = 1; $i < $n; $i = $i + 1) {
        $kk = $keys[$i];
        $j = $i - 1;
        while ($j >= 0 && $keys[$j] < $kk) { $keys[$j + 1] = $keys[$j]; $j = $j - 1; }
        $keys[$j + 1] = $kk;
    }
    $new = [];
    foreach ($keys as $k) { $new[$k] = $arr[$k]; }
    $arr = $new;
    return true;
}

/**
 * `asort` / `arsort` — sort by VALUE (ascending / descending), preserving the
 * key=>value association. Insertion sort over the KEY vector, comparing the
 * by-key value reads `$arr[$key]` (tagged cells → __manticore_tagged_compare).
 */
function asort(array &$arr): bool
{
    $keys = array_keys($arr);
    $n = count($keys);
    for ($i = 1; $i < $n; $i = $i + 1) {
        $kk = $keys[$i];
        $vv = $arr[$kk];
        $j = $i - 1;
        while ($j >= 0 && $arr[$keys[$j]] > $vv) { $keys[$j + 1] = $keys[$j]; $j = $j - 1; }
        $keys[$j + 1] = $kk;
    }
    $new = [];
    foreach ($keys as $k) { $new[$k] = $arr[$k]; }
    $arr = $new;
    return true;
}

function arsort(array &$arr): bool
{
    $keys = array_keys($arr);
    $n = count($keys);
    for ($i = 1; $i < $n; $i = $i + 1) {
        $kk = $keys[$i];
        $vv = $arr[$kk];
        $j = $i - 1;
        while ($j >= 0 && $arr[$keys[$j]] < $vv) { $keys[$j + 1] = $keys[$j]; $j = $j - 1; }
        $keys[$j + 1] = $kk;
    }
    $new = [];
    foreach ($keys as $k) { $new[$k] = $arr[$k]; }
    $arr = $new;
    return true;
}

/**
 * `array_reverse(arr)` — values in reverse order, reindexed. In the PRELUDE so
 * call-site element inference / monomorphization types `$a` CONCRETELY (vec[int]
 * etc.) — array_values then re-boxes each typed element to a tagged cell. A
 * `mixed[]` docblock would instead type `$a` a vec[CELL], which the call site
 * does not element-box (it passes the raw vec[int]) → the elements stay raw and
 * canonical NaN-boxing misreads them as doubles. So leave `$a` a bare `array`.
 */
function array_reverse(array $a): array
{
    $vals = array_values($a);
    $out = [];
    $i = count($vals) - 1;
    while ($i >= 0) {
        $out[] = $vals[$i];
        $i = $i - 1;
    }
    return $out;
}

/**
 * `array_pad(arr, size, value)` — pad to abs(size) with `value` (right when
 * size > 0, left when size < 0); a shorter `size` returns the input values.
 * PRELUDE (see array_reverse) so call-site element inference types `$arr`
 * concretely — leave it a bare `array` (a `mixed[]` docblock → vec[cell] →
 * unboxed raw elements at the call boundary).
 *
 * @param mixed $value
 */
function array_pad(array $arr, int $size, mixed $value): array
{
    $vals = array_values($arr);
    $n = count($vals);
    $want = $size < 0 ? -$size : $size;
    if ($want <= $n) { return $vals; }
    $pad = $want - $n;
    $out = [];
    if ($size < 0) {
        for ($i = 0; $i < $pad; $i = $i + 1) { $out[] = $value; }
        foreach ($vals as $v) { $out[] = $v; }
    } else {
        foreach ($vals as $v) { $out[] = $v; }
        for ($i = 0; $i < $pad; $i = $i + 1) { $out[] = $value; }
    }
    return $out;
}

/**
 * `explode(delim, subject [, limit])`. PRELUDE, not the stdlib .o: compiled WITH
 * the user program its return narrows to `vec[string]` (every `$out[]` is a
 * `substr`); the standalone-.o build erased the bare-`array` return and box-
 * tagged each segment into a `vec[cell]` (~0.7x php — the boxing, NOT the loop,
 * was the cost). `$h = (int)$hit` unboxes the `int|false` cell once so the two
 * position computations are raw int math, not tagged-cell arithmetic. The
 * compiler itself calls explode (lexer/parser), so the Zend cold-seed must inject
 * this too — find_prelude_src reads via \file_get_contents + MANTICORE_PRELUDE.
 */
function explode(string $delim, string $subject, int $limit = PHP_INT_MAX): array
{
    if ($delim === '') { return [$subject]; }
    if ($limit === 0) { $limit = 1; }
    $dLen = \strlen($delim);
    $subLen = \strlen($subject);
    $out = [];
    $pos = 0;
    while ($limit > 1) {
        $hit = \strpos($subject, $delim, $pos);
        if ($hit === false) { break; }
        $h = (int)$hit;
        $out[] = \substr($subject, $pos, $h - $pos);
        $pos = $h + $dLen;
        $limit = $limit - 1;
    }
    $out[] = \substr($subject, $pos, $subLen - $pos);
    return $out;
}
