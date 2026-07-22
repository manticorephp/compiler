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
 *
 * `mixed[]` is the CANONICAL element type, matching uasort/uksort. A prelude fn
 * is emitted linkonce_odr into every module and coalesced to ONE copy, so its
 * body may not be inferred from the call sites one module happens to see —
 * that gave sort() a vec[string] body (rc) in one object and a vec[int] one
 * (arena) in another, under a single symbol. Declaring the erased type here
 * makes every module compile the SAME body; a concrete caller still gets a fast
 * vec[T] copy from Monomorphize, under its own `$mono$` symbol.
 * @param mixed[] $arr
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

/**
 * Canonical `mixed[]` element — see the note on usort: a linkonce_odr prelude
 * body must not be specialised from the call sites of one module.
 * @param mixed[] $arr
 */
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

/**
 * Canonical `mixed[]` element — see the note on usort.
 * @param mixed[] $arr
 */
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
function array_reverse(array $a, bool $preserve_keys = false): array
{
    // php: STRING keys are ALWAYS kept; INT keys are re-indexed unless
    // $preserve_keys. The old body used array_values and dropped every key.
    $keys = \array_keys($a);
    $out = [];
    $i = \count($keys) - 1;
    while ($i >= 0) {
        $k = $keys[$i];
        if ($preserve_keys || \is_string($k)) { $out[$k] = $a[$k]; }
        else { $out[] = $a[$k]; }
        $i = $i - 1;
    }
    return $out;
}

/**
 * `array_slice($arr, $offset, $length, $preserve_keys)`. PRELUDE (see
 * array_reverse): bare-`array` param so call-site element inference types the
 * element and the copied values ride it (a stdlib extern would erase → NaN-box
 * misreads a raw int as a double). PHP semantics: STRING keys always kept, INT
 * keys reindexed from 0 unless `$preserve_keys`.
 */
function array_slice(array $arr, int $offset, ?int $length = null, bool $preserve_keys = false): array
{
    $count = count($arr);
    if ($offset < 0) { $offset = max(0, $count + $offset); }
    if ($length === null) {
        $end = $count;
    } elseif ($length < 0) {
        $end = max($offset, $count + $length);
    } else {
        $end = min($count, $offset + $length);
    }
    $out = [];
    $i = 0;
    foreach ($arr as $k => $v) {
        if ($i >= $end) { break; }
        if ($i >= $offset) {
            if (is_string($k)) { $out[$k] = $v; }
            elseif ($preserve_keys) { $out[$k] = $v; }
            else { $out[] = $v; }
        }
        $i = $i + 1;
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

/**
 * `array_combine(keys, values)` — a new array pairing each key with the value at
 * the same position. Both are re-listed into typed in-module vecs first so the
 * result's keys/values carry real types (an erased stdlib return would misbox).
 */
function array_combine(array $keys, array $values): array
{
    $ks = [];
    foreach ($keys as $k) { $ks[] = $k; }
    $vs = [];
    foreach ($values as $v) { $vs[] = $v; }
    $out = [];
    $n = \count($ks);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $out[$ks[$i]] = $vs[$i];
    }
    return $out;
}

/**
 * `array_fill_keys(keys, value)` — every key mapped to the same value.
 */
function array_fill_keys(array $keys, mixed $value): array
{
    $out = [];
    foreach ($keys as $k) { $out[$k] = $value; }
    return $out;
}

/**
 * `array_diff(arr, ...others)` — elements of `$arr` whose (string) value is in
 * none of the other arrays, keys preserved (PHP compares values as strings).
 */
function array_diff(array $arr, array ...$others): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $found = false;
        foreach ($others as $o) {
            foreach ($o as $w) {
                if ($w == $v) { $found = true; break; }
            }
            if ($found) { break; }
        }
        if (!$found) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_intersect(arr, ...others)` — elements of `$arr` whose (string) value is
 * present in EVERY other array, keys preserved.
 */
function array_intersect(array $arr, array ...$others): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $inAll = true;
        foreach ($others as $o) {
            $inThis = false;
            foreach ($o as $w) {
                if ($w == $v) { $inThis = true; break; }
            }
            if (!$inThis) { $inAll = false; break; }
        }
        if ($inAll) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_diff_key(arr, ...others)` — entries of `$arr` whose KEY appears in none
 * of the other arrays.
 */
function array_diff_key(array $arr, array ...$others): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $ks = (string)$k;
        $found = false;
        foreach ($others as $o) {
            foreach ($o as $ok => $_) {
                if ((string)$ok === $ks) { $found = true; break; }
            }
            if ($found) { break; }
        }
        if (!$found) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_intersect_key(arr, ...others)` — entries of `$arr` whose KEY appears in
 * every other array.
 */
function array_intersect_key(array $arr, array ...$others): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $ks = (string)$k;
        $inAll = true;
        foreach ($others as $o) {
            $inThis = false;
            foreach ($o as $ok => $_) {
                if ((string)$ok === $ks) { $inThis = true; break; }
            }
            if (!$inThis) { $inAll = false; break; }
        }
        if ($inAll) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_unique(arr)` — first occurrence of each (string) value, keys preserved
 * (PHP default SORT_STRING comparison).
 */
function array_unique(array $arr): array
{
    /** @var mixed[] $out */
    $out = [];
    foreach ($arr as $k => $v) {
        $dup = false;
        foreach ($out as $w) {
            if ($w == $v) { $dup = true; break; }
        }
        if (!$dup) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_count_values(arr)` — map each (int|string) value to how many times it
 * occurs.
 */
function array_count_values(array $arr): array
{
    $out = [];
    foreach ($arr as $v) {
        if (isset($out[$v])) { $out[$v] = $out[$v] + 1; }
        else { $out[$v] = 1; }
    }
    return $out;
}

/**
 * `array_chunk(arr, size, preserve_keys)` — split into sub-arrays of `$size`.
 */
function array_chunk(array $arr, int $size, bool $preserve_keys = false): array
{
    if ($size < 1) { $size = 1; }
    $out = [];
    $chunk = [];
    $i = 0;
    foreach ($arr as $k => $v) {
        if ($preserve_keys) { $chunk[$k] = $v; } else { $chunk[] = $v; }
        $i = $i + 1;
        if ($i === $size) { $out[] = $chunk; $chunk = []; $i = 0; }
    }
    if ($i > 0) { $out[] = $chunk; }
    return $out;
}

/**
 * `array_replace(arr, ...others)` — later arrays overwrite earlier entries by
 * key (a shallow, non-recursive merge that keeps string AND int keys).
 */
function array_replace(array $arr, array ...$others): array
{
    $out = [];
    foreach ($arr as $k => $v) { $out[$k] = $v; }
    foreach ($others as $o) {
        foreach ($o as $k => $v) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_push(arr, ...values)` — append each value, returning the new count.
 */
function array_push(array &$arr, mixed ...$values): int
{
    foreach ($values as $v) { $arr[] = $v; }
    return \count($arr);
}

/**
 * `uasort(arr, cmp)` — sort by VALUE with a user comparison, keys preserved
 * (insertion sort, so stable). The values are materialised into a LOCAL typed
 * list via `foreach` first, then compared by int index — the same shape that
 * lets `usort` pass values into the callback. Reading `$arr[$dynamicKey]`
 * straight into the callback instead would hand it a raw (unboxed) value, since
 * an element read by a dynamic key off a bare-`array` param erases its type.
 * @param mixed[] $arr
 */
function uasort(array &$arr, callable $cmp): bool
{
    // Decorate-sort-undecorate over usort, preserving keys. Correct for every
    // comparator — `$a <=> $b`, `strcmp(...)`, value-extracting, AND bare integer
    // arithmetic (`fn($x, $y) => $x - $y`): the callable dimension monomorphizes
    // `$cmp`/`$arr`, and the decorated pair's boxed values de-cellify back to the
    // byref param's concrete representation at the `$arr = $new` writeback.
    $pairs = [];
    foreach ($arr as $k => $v) { $pairs[] = ["k" => $k, "v" => $v]; }
    usort($pairs, fn($a, $b) => $cmp($a["v"], $b["v"]));
    $new = [];
    foreach ($pairs as $p) { $new[$p["k"]] = $p["v"]; }
    $arr = $new;
    return true;
}

/**
 * `uksort(arr, cmp)` — sort by KEY with a user comparison, values kept with
 * their keys.
 * @param mixed[] $arr
 */
function uksort(array &$arr, callable $cmp): bool
{
    $keys = array_keys($arr);
    $n = count($keys);
    for ($i = 1; $i < $n; $i = $i + 1) {
        $kk = $keys[$i];
        $j = $i - 1;
        while ($j >= 0 && $cmp($keys[$j], $kk) > 0) {
            $keys[$j + 1] = $keys[$j];
            $j = $j - 1;
        }
        $keys[$j + 1] = $kk;
    }
    $new = [];
    foreach ($keys as $k) { $new[$k] = $arr[$k]; }
    $arr = $new;
    return true;
}
