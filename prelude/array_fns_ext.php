<?php

/**
 * EXTENDED array functions — the `ref.array` surface beyond the hot core, kept
 * in a SEPARATE prelude file from array_fns.php on purpose.
 *
 * A prelude file is injected WHOLE when the program calls ANY function it
 * defines (Main.php, PreludeDemand::definedFunctions). The compiler's own
 * source calls array_map/array_slice/sort, so everything in array_fns.php is
 * compiled INTO THE COMPILER — one miscompiled helper there breaks generation 2
 * of the self-host build. That is exactly how the first attempt at these
 * functions died. Nothing here is called by src/, so this file never enters the
 * compiler's own build and cannot break it (tools/prelude_ext_gate.sh asserts
 * that invariant).
 *
 * RULES for anything added here:
 *  - A by-ref array param is `array &$x` with `@param mixed[]` — NEVER a
 *    concrete element type. InferScans::scanCallSiteRefParams refuses to narrow
 *    a prelude by-ref param (prelude bodies are linkonce_odr, so one module's
 *    call sites are not all of them); a narrowed param gives one object an
 *    arena body and another an rc body. Speed comes from Monomorphize's
 *    `$mono$` specialization, not from the declared type.
 *  - `#[RefOut]` is for FRESH out-params only. An IN-OUT by-ref array is a
 *    plain `array &$x`.
 *  - Element-typed / callback-taking functions can NOT live in the stdlib .o
 *    (the bare-`array` param's element erases to unknown there) — same reason
 *    array_map/usort live in the prelude.
 *
 * ⚠ STATUS — every function BELOW is exercised against php by an AOT case
 * (array_callback_byref · array_assoc_set_ops · array_predicate_case ·
 * array_splice_fn · array_ucompare_family · array_random_fns ·
 * array_recursive_merges):
 * array_diff_assoc · array_intersect_assoc · array_diff_ukey ·
 * array_intersect_ukey · array_udiff · array_uintersect · array_udiff_assoc ·
 * array_uintersect_assoc · array_diff_uassoc · array_intersect_uassoc ·
 * array_udiff_uassoc · array_uintersect_uassoc · array_walk (by-value AND
 * `&$value`) · array_walk_recursive · array_splice · array_replace_recursive ·
 * array_merge_recursive · shuffle · array_rand · array_all · array_any ·
 * array_find · array_find_key · array_change_key_case.
 *
 * A RECURSIVE function here rebuilds both sides into literal-bound locals with
 * an `@var array<array-key, mixed>` docblock binding before the self-call: the
 * values arrive as CELLS, a cell argument gives Monomorphize an empty callKey,
 * and without a definite repr the erased body runs for every nested level.
 *
 * NOT HERE, blocked on a named compiler root cause:
 *  - array_multisort — variadic BY-REF parallel arrays with interleaved SORT_*
 *    flags; needs by-ref variadic packs first.
 *
 * `array_merge_recursive` used to sit in that list ("an argument carrying BOTH
 * int and string keys mangles a collision"). It was never this file's bug —
 * a mixed-key LITERAL typed its key by unioning the element key kinds, and
 * `string ∪ int` collapses to UNKNOWN, which is neither an assoc (isAssoc() is
 * string-key-only) nor a cell-keyed array, so foreach fell back to the raw i64
 * key channel. Three compiler fixes cleared it — see the mixed_key_literal AOT
 * case, which is what owns them now.
 */

/**
 * `array_diff_assoc(arr, ...others)` — retain entries whose key/value pair is
 * absent from every other array. PHP compares both keys and values as strings.
 */
function array_diff_assoc(array $arr, array ...$others): array
{
    $out = [];
    $keys = array_keys($arr);
    foreach ($keys as $key) {
        $value = $arr[$key];
        $found = false;
        foreach ($others as $other) {
            if (array_key_exists($key, $other) && $other[$key] == $value) {
                $found = true;
                break;
            }
        }
        if (!$found) { $out[$key] = $value; }
    }
    return $out;
}

/**
 * `array_intersect_assoc(arr, ...others)` — retain entries whose key/value
 * pair occurs in every other array; keys from the first array are preserved.
 */
function array_intersect_assoc(array $arr, array ...$others): array
{
    $out = [];
    $keys = array_keys($arr);
    foreach ($keys as $key) {
        $value = $arr[$key];
        $inAll = true;
        foreach ($others as $other) {
            if (!array_key_exists($key, $other) || !($other[$key] == $value)) {
                $inAll = false;
                break;
            }
        }
        if ($inAll) { $out[$key] = $value; }
    }
    return $out;
}

/**
 * `array_diff_ukey(arr, arr2, cb)` — entries of `$arr` whose KEY matches no key
 * of `$arr2` under the user comparator (`$cb($k1,$k2) === 0` ⇒ equal). The
 * callable dimension monomorphizes `$cb`, so a string-name comparator like
 * `'strcasecmp'` resolves to a direct call. (symfony's Windows env-merge form.)
 */
function array_diff_ukey(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        $found = false;
        foreach ($arr2 as $ok => $_) {
            if ($cb($k, $ok) === 0) { $found = true; break; }
        }
        if (!$found) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * `array_intersect_ukey(arr, arr2, cb)` — entries of `$arr` whose KEY matches
 * some key of `$arr2` under the user comparator.
 */
function array_intersect_ukey(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    foreach ($arr as $k => $v) {
        foreach ($arr2 as $ok => $_) {
            if ($cb($k, $ok) === 0) { $out[$k] = $v; break; }
        }
    }
    return $out;
}

/**
 * `array_udiff(arr, arr2, cb)` — entries of `$arr` whose VALUE matches no value
 * of `$arr2` under the user comparator (`$cb($v1,$v2) === 0` ⇒ equal).
 *
 * PHP declares the u* family variadic with the callbacks last
 * (`array_udiff(array $array, array ...$arrays, callable $cb)`); the TWO-array
 * form is what this file implements throughout, like array_diff_ukey above —
 * a callback inside a variadic pack never reaches Monomorphize's callable
 * dimension, so it would degrade to a dynamic invoke.
 */
function array_udiff(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    $keys = array_keys($arr);
    foreach ($keys as $key) {
        $value = $arr[$key];
        $found = false;
        foreach ($arr2 as $ov) {
            if ($cb($value, $ov) === 0) { $found = true; break; }
        }
        if (!$found) { $out[$key] = $value; }
    }
    return $out;
}

/**
 * `array_uintersect(arr, arr2, cb)` — entries of `$arr` whose VALUE matches some
 * value of `$arr2` under the user comparator.
 */
function array_uintersect(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    $keys = array_keys($arr);
    foreach ($keys as $key) {
        $value = $arr[$key];
        foreach ($arr2 as $ov) {
            if ($cb($value, $ov) === 0) { $out[$key] = $value; break; }
        }
    }
    return $out;
}

/**
 * `array_udiff_assoc(arr, arr2, cb)` — keys are compared internally, values with
 * the user comparator: keep an entry unless `$arr2` holds the SAME key with a
 * value the comparator calls equal.
 */
function array_udiff_assoc(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    $keys = array_keys($arr);
    foreach ($keys as $key) {
        $value = $arr[$key];
        $same = false;
        if (array_key_exists($key, $arr2) && $cb($value, $arr2[$key]) === 0) { $same = true; }
        if (!$same) { $out[$key] = $value; }
    }
    return $out;
}

/**
 * `array_uintersect_assoc(arr, arr2, cb)` — the same pairing, kept instead of
 * dropped: the key must exist in `$arr2` and the values compare equal.
 */
function array_uintersect_assoc(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    $keys = array_keys($arr);
    foreach ($keys as $key) {
        $value = $arr[$key];
        if (array_key_exists($key, $arr2) && $cb($value, $arr2[$key]) === 0) {
            $out[$key] = $value;
        }
    }
    return $out;
}

/**
 * `array_diff_uassoc(arr, arr2, cb)` — the mirror image of array_udiff_assoc:
 * KEYS are compared with the user comparator, values internally.
 */
function array_diff_uassoc(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    $keys = array_keys($arr);
    $otherKeys = array_keys($arr2);
    foreach ($keys as $key) {
        $value = $arr[$key];
        $found = false;
        foreach ($otherKeys as $ok) {
            if ($cb($key, $ok) === 0 && $arr2[$ok] == $value) { $found = true; break; }
        }
        if (!$found) { $out[$key] = $value; }
    }
    return $out;
}

/**
 * `array_intersect_uassoc(arr, arr2, cb)` — keep an entry whose key matches some
 * key of `$arr2` under the comparator AND whose value is equal.
 */
function array_intersect_uassoc(array $arr, array $arr2, callable $cb): array
{
    $out = [];
    $keys = array_keys($arr);
    $otherKeys = array_keys($arr2);
    foreach ($keys as $key) {
        $value = $arr[$key];
        foreach ($otherKeys as $ok) {
            if ($cb($key, $ok) === 0 && $arr2[$ok] == $value) { $out[$key] = $value; break; }
        }
    }
    return $out;
}

/**
 * `array_udiff_uassoc(arr, arr2, valueCb, keyCb)` — both halves user-compared:
 * drop an entry only when `$arr2` holds a key the key-comparator calls equal
 * whose value the value-comparator also calls equal.
 */
function array_udiff_uassoc(array $arr, array $arr2, callable $valueCb, callable $keyCb): array
{
    $out = [];
    $keys = array_keys($arr);
    $otherKeys = array_keys($arr2);
    foreach ($keys as $key) {
        $value = $arr[$key];
        $found = false;
        foreach ($otherKeys as $ok) {
            if ($keyCb($key, $ok) === 0 && $valueCb($value, $arr2[$ok]) === 0) {
                $found = true;
                break;
            }
        }
        if (!$found) { $out[$key] = $value; }
    }
    return $out;
}

/**
 * `array_uintersect_uassoc(arr, arr2, valueCb, keyCb)` — the intersecting half of
 * the same pairing.
 */
function array_uintersect_uassoc(array $arr, array $arr2, callable $valueCb, callable $keyCb): array
{
    $out = [];
    $keys = array_keys($arr);
    $otherKeys = array_keys($arr2);
    foreach ($keys as $key) {
        $value = $arr[$key];
        foreach ($otherKeys as $ok) {
            if ($keyCb($key, $ok) === 0 && $valueCb($value, $arr2[$ok]) === 0) {
                $out[$key] = $value;
                break;
            }
        }
    }
    return $out;
}

/**
 * `array_walk(&$arr, cb, extra)` — apply `$cb($value, $key, $extra)` to every
 * entry.
 *
 * TODO(by-ref callback): a leaf mutated through a `&$value` callback param does
 * not propagate yet — EmitLlvmCalls::emitInvoke boxes every argument into a cell
 * and never consults the callee's by-ref mask (`sigs->refParams`), which the
 * NAMED-call path at EmitLlvmCalls.php:869-909 does honour. Until that lands,
 * the `$arr[$k] = $v` copy-back below keeps by-VALUE callbacks exact.
 * @param mixed[] $arr
 */
function array_walk(array &$arr, callable $cb, mixed $extra = null): bool
{
    foreach ($arr as $k => $v) {
        $cb($v, $k, $extra);
        $arr[$k] = $v;
    }
    return true;
}

/**
 * `array_walk_recursive(&$arr, cb, extra)` — like array_walk but descends into
 * array leaves, invoking `$cb` only on scalar leaves.
 * @param mixed[] $arr
 */
function array_walk_recursive(array &$arr, callable $cb, mixed $extra = null): bool
{
    foreach ($arr as $k => $v) {
        if (\is_array($v)) {
            array_walk_recursive($v, $cb, $extra);
            $arr[$k] = $v;
        } else {
            $cb($v, $k, $extra);
            $arr[$k] = $v;
        }
    }
    return true;
}

/**
 * `array_splice(&$input, offset, length, replacement)` — remove a slice and
 * splice a replacement in its place, returning the removed entries.
 *
 * PHP reindexes INT keys on both sides and keeps STRING keys, so the rebuild
 * walks `array_keys` and re-adds each entry by the kind of its key. The whole
 * array is rebuilt into a fresh local and assigned back in one store — an
 * in-place shift would write past the live length of the buffer it is reading.
 * @param mixed[] $input
 * @return mixed[]
 */
function array_splice(array &$input, int $offset, ?int $length = null, mixed $replacement = []): array
{
    $keys = array_keys($input);
    $n = count($keys);
    $start = $offset;
    if ($start < 0) { $start = $n + $start; }
    if ($start < 0) { $start = 0; }
    if ($start > $n) { $start = $n; }
    $len = $n - $start;
    if ($length !== null) {
        $len = $length;
        if ($len < 0) { $len = $n - $start + $len; }
    }
    if ($len < 0) { $len = 0; }
    $end = $start + $len;
    if ($end > $n) { $end = $n; }

    $out = [];
    $removed = [];
    $i = 0;
    while ($i < $start) {
        $k = $keys[$i];
        if (is_string($k)) { $out[$k] = $input[$k]; } else { $out[] = $input[$k]; }
        $i = $i + 1;
    }
    // Branch the LOOP, not the value. TODO(array|cell merge): a local that
    // merges an array literal with a `mixed` value — `$repl = []; if
    // (is_array($replacement)) { $repl = $replacement; }` — types `unknown`
    // (vec[unknown] ∪ cell), so the `[]` store writes a raw buffer pointer into
    // a slot the foreach then reads as a NaN-boxed cell → SIGSEGV. Pre-existing
    // and not specific to this function; planMergeShadow boxes such a merge only
    // for SCALAR kinds today.
    if (is_array($replacement)) {
        foreach ($replacement as $rv) { $out[] = $rv; }
    } else {
        $out[] = $replacement;
    }
    $i = $start;
    while ($i < $end) {
        $k = $keys[$i];
        if (is_string($k)) { $removed[$k] = $input[$k]; } else { $removed[] = $input[$k]; }
        $i = $i + 1;
    }
    $i = $end;
    while ($i < $n) {
        $k = $keys[$i];
        if (is_string($k)) { $out[$k] = $input[$k]; } else { $out[] = $input[$k]; }
        $i = $i + 1;
    }
    $input = $out;
    return $removed;
}

/**
 * `array_replace_recursive(arr, ...others)` — later arrays overwrite earlier
 * ones key by key; two ARRAY values at the same key merge instead of replacing.
 */
function array_replace_recursive(array $arr, array ...$others): array
{
    /** @var array<array-key, mixed> $out */
    $out = [];
    foreach ($arr as $k => $v) { $out[$k] = $v; }
    foreach ($others as $other) {
        foreach ($other as $k => $v) {
            if (\is_array($v) && array_key_exists($k, $out) && \is_array($out[$k])) {
                // Rebuild both sides into LITERAL-bound locals before recursing:
                // `$out[$k]` and `$v` are CELLS holding arrays, and a cell arg
                // gives Monomorphize an empty callKey, so the erased body would
                // run for every nested level. A rebuilt local carries a definite
                // repr — and the `@var` says which one, so the copy loop cannot
                // narrow it to whatever the first entry happened to be.
                $inner = $out[$k];
                /** @var array<array-key, mixed> $left */
                $left = [];
                foreach ($inner as $ik => $iv) { $left[$ik] = $iv; }
                /** @var array<array-key, mixed> $right */
                $right = [];
                foreach ($v as $rk => $rv) { $right[$rk] = $rv; }
                $out[$k] = array_replace_recursive($left, $right);
            } else {
                $out[$k] = $v;
            }
        }
    }
    return $out;
}

/**
 * `array_merge_recursive(arr, ...others)` — like array_merge (INT keys append
 * and RENUMBER, string keys overwrite), except that a STRING-key collision
 * MERGES instead of overwriting: php promotes both sides with a `(array)` cast
 * and merges them recursively, so two scalars under one key become a list of
 * both (`['a'=>'x'] + ['a'=>'y']` → `['a'=>['x','y']]`) and a scalar meeting an
 * array joins that array.
 *
 * Same recursion discipline as {@see array_replace_recursive}: both sides are
 * rebuilt into literal-bound locals carrying an `@var array<array-key, mixed>`
 * before the self-call, because the values arrive as CELLS and a cell argument
 * gives Monomorphize an empty callKey.
 *
 * php's own signature is `array_merge_recursive(array ...$arrays)`; the leading
 * `array $arr` is what gives Monomorphize a specialization dimension, matching
 * array_replace_recursive. `array_merge_recursive()` with no argument is the
 * only shape that diverges (php returns []).
 */
function array_merge_recursive(array $arr, array ...$others): array
{
    /** @var array<array-key, mixed> $out */
    $out = [];
    foreach ($arr as $k => $v) {
        if (\is_int($k)) { $out[] = $v; } else { $out[$k] = $v; }
    }
    foreach ($others as $other) {
        foreach ($other as $k => $v) {
            if (\is_int($k)) {
                // php renumbers every int key on a merge — never preserve it.
                $out[] = $v;
            } elseif (!array_key_exists($k, $out)) {
                $out[$k] = $v;
            } else {
                // Collision: both sides become arrays, then merge. A non-array
                // side becomes the one-element list php's `(array)` cast makes,
                // which is what turns two scalars into ['x','y'].
                $cur = $out[$k];
                /** @var array<array-key, mixed> $left */
                $left = [];
                if (\is_array($cur)) {
                    foreach ($cur as $lk => $lv) {
                        if (\is_int($lk)) { $left[] = $lv; } else { $left[$lk] = $lv; }
                    }
                } else {
                    $left[] = $cur;
                }
                /** @var array<array-key, mixed> $right */
                $right = [];
                if (\is_array($v)) {
                    foreach ($v as $rk => $rv) {
                        if (\is_int($rk)) { $right[] = $rv; } else { $right[$rk] = $rv; }
                    }
                } else {
                    $right[] = $v;
                }
                $out[$k] = array_merge_recursive($left, $right);
            }
        }
    }
    return $out;
}

/**
 * `shuffle(&$array)` — randomise the order, dropping the keys (PHP reindexes).
 * Fisher-Yates over a rebuilt list; entropy comes from `random_int`, the one
 * generator this runtime has (there is no `mt_srand`, so a shuffle is never
 * reproducible — a test can only assert the multiset, never the order).
 * @param mixed[] $array
 */
function shuffle(array &$array): bool
{
    $values = [];
    foreach ($array as $v) { $values[] = $v; }
    $i = count($values) - 1;
    while ($i > 0) {
        $j = random_int(0, $i);
        $tmp = $values[$i];
        $values[$i] = $values[$j];
        $values[$j] = $tmp;
        $i = $i - 1;
    }
    $array = $values;
    return true;
}

/**
 * `array_rand(arr, num)` — one random KEY, or a list of `$num` distinct keys in
 * the array's own order (PHP guarantees that order). Selection sampling: walk
 * the keys once and take each with probability (still-needed / still-left),
 * which is uniform without a reject-and-retry loop.
 */
function array_rand(array $array, int $num = 1): mixed
{
    $keys = array_keys($array);
    $n = count($keys);
    if ($n === 0) {
        throw new \ValueError('array_rand(): Argument #1 ($array) cannot be empty');
    }
    if ($num < 1 || $num > $n) {
        throw new \ValueError(
            'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1'
        );
    }
    if ($num === 1) { return $keys[random_int(0, $n - 1)]; }
    $out = [];
    $need = $num;
    $i = 0;
    while ($i < $n && $need > 0) {
        $left = $n - $i;
        if (random_int(1, $left) <= $need) {
            $out[] = $keys[$i];
            $need = $need - 1;
        }
        $i = $i + 1;
    }
    return $out;
}

/**
 * Compare two column values under one `SORT_*` flag. `SORT_FLAG_CASE` (8) is
 * an OR-able modifier on the STRING and NATURAL bases, so it is masked off
 * first. `SORT_REGULAR` is a plain `<=>`, which on cells lowers to the runtime
 * tag-dispatched compare — the same one ksort gets for NaN-boxed keys.
 */
function __mc_multisort_cmp(mixed $a, mixed $b, int $flag): int
{
    $ci = ($flag & 8) !== 0;
    $base = $flag & ~8;
    if ($base === 1) {
        // SORT_NUMERIC
        $fa = (float)$a;
        $fb = (float)$b;
        if ($fa < $fb) { return -1; }
        if ($fa > $fb) { return 1; }
        return 0;
    }
    if ($base === 2 || $base === 5) {
        // SORT_STRING / SORT_LOCALE_STRING (no locale support — byte order).
        $sa = (string)$a;
        $sb = (string)$b;
        // NOT strcasecmp: the libc bind returns a C `int` that this tree does
        // not sign-extend, so a negative comes back as 4294967295 and every
        // case-insensitive compare read as "greater". strtolower + strcmp is
        // exact and stays clear of the broken binding.
        $r = $ci ? \strcmp(\strtolower($sa), \strtolower($sb)) : \strcmp($sa, $sb);
        if ($r < 0) { return -1; }
        if ($r > 0) { return 1; }
        return 0;
    }
    if ($base === 6) {
        // SORT_NATURAL
        $sa = (string)$a;
        $sb = (string)$b;
        $r = $ci ? \strnatcasecmp($sa, $sb) : \strnatcmp($sa, $sb);
        if ($r < 0) { return -1; }
        if ($r > 0) { return 1; }
        return 0;
    }
    return $a <=> $b;
}

/**
 * Compare row `$i` against row `$j` across every column, first column first,
 * later columns breaking ties. `$flat` holds the columns end to end — column
 * `c` row `r` is `$flat[$c * $n + $r]` — so every read is one-dimensional; a
 * nested `$cols[$c][$r]` off an array-of-arrays would index through a CELL.
 * @param mixed[] $flat
 * @param int[]   $orders
 * @param int[]   $flags
 */
function __mc_multisort_rowcmp(array $flat, int $n, int $ncol, array $orders, array $flags, int $i, int $j): int
{
    $c = 0;
    while ($c < $ncol) {
        $r = __mc_multisort_cmp($flat[$c * $n + $i], $flat[$c * $n + $j], $flags[$c]);
        // SORT_DESC is 3.
        if ($orders[$c] === 3) { $r = -$r; }
        if ($r !== 0) { return $r; }
        $c = $c + 1;
    }
    return 0;
}

/**
 * `array_multisort` phase 1 — the row PERMUTATION, as a list of source
 * positions. `$cols` are the column value-lists in argument order, `$orders`
 * and `$flags` their per-column `SORT_ASC`/`SORT_DESC` and `SORT_*` settings.
 *
 * Bottom-up STABLE merge sort over an index list, the same shape (and the same
 * copy-only, never-swap discipline) as the sorts in array_fns.php: an in-place
 * swap leaves a dangling temp on an rc'd element.
 *
 * The call site cannot pass parallel arrays BY REF — a by-ref variadic pack
 * does not exist — so `array_multisort` is desugared in LowerExprs to this
 * permutation call plus one {@see __mc_multisort_apply} write-back per column.
 * @param mixed[] $cols
 * @param int[]   $orders
 * @param int[]   $flags
 * @return int[]
 */
function __mc_multisort_order(array $cols, array $orders, array $flags): array
{
    /** @var mixed[] $flat */
    $flat = [];
    $n = 0;
    $ncol = 0;
    foreach ($cols as $col) {
        $len = 0;
        foreach ($col as $v) { $flat[] = $v; $len = $len + 1; }
        if ($ncol === 0) { $n = $len; }
        elseif ($len !== $n) { throw new \ValueError('Array sizes are inconsistent'); }
        $ncol = $ncol + 1;
    }
    /** @var int[] $idx */
    $idx = [];
    $i = 0;
    while ($i < $n) { $idx[] = $i; $i = $i + 1; }
    if ($n < 2) { return $idx; }

    /** @var int[] $tmp */
    $tmp = [];
    $i = 0;
    while ($i < $n) { $tmp[] = 0; $i = $i + 1; }
    $width = 1;
    $inIdx = true;
    while ($width < $n) {
        $lo = 0;
        while ($lo < $n) {
            $mid = $lo + $width; if ($mid > $n) { $mid = $n; }
            $hi = $mid + $width; if ($hi > $n) { $hi = $n; }
            $a = $lo;
            $b = $mid;
            $k = $lo;
            while ($k < $hi) {
                if ($a >= $mid) {
                    if ($inIdx) { $tmp[$k] = $idx[$b]; } else { $idx[$k] = $tmp[$b]; }
                    $b = $b + 1;
                } elseif ($b >= $hi) {
                    if ($inIdx) { $tmp[$k] = $idx[$a]; } else { $idx[$k] = $tmp[$a]; }
                    $a = $a + 1;
                } else {
                    $ra = $inIdx ? $idx[$a] : $tmp[$a];
                    $rb = $inIdx ? $idx[$b] : $tmp[$b];
                    // `<= 0` keeps equal rows in source order — stability is
                    // what makes a later column break the earlier one's ties.
                    if (__mc_multisort_rowcmp($flat, $n, $ncol, $orders, $flags, $ra, $rb) <= 0) {
                        if ($inIdx) { $tmp[$k] = $ra; } else { $idx[$k] = $ra; }
                        $a = $a + 1;
                    } else {
                        if ($inIdx) { $tmp[$k] = $rb; } else { $idx[$k] = $rb; }
                        $b = $b + 1;
                    }
                }
                $k = $k + 1;
            }
            $lo = $lo + $width + $width;
        }
        $inIdx = !$inIdx;
        $width = $width + $width;
    }
    if (!$inIdx) {
        $i = 0;
        while ($i < $n) { $idx[$i] = $tmp[$i]; $i = $i + 1; }
    }
    return $idx;
}

/**
 * `array_multisort` phase 2 — rewrite one column in permutation order. PHP
 * keeps STRING keys and RE-INDEXES numeric ones, so `['x'=>3,'y'=>1,5=>2]`
 * sorts to `['y'=>1, 0=>2, 'x'=>3]`.
 *
 * BY-REF with a rebuild-and-assign-back (shuffle's shape), NOT a value-return
 * the caller assigns. A returned `array<array-key, mixed>` is cell-element, and
 * storing that back into a column whose slot is a concrete `vec[string]` left
 * the slot's release treating cell bits as string pointers — a SIGSEGV at
 * teardown that a `vec[int]` column happened to survive. A by-ref param is
 * specialized per caller by Monomorphize (InferScans deliberately refuses to
 * narrow a prelude by-ref param), so each column keeps its own repr, exactly
 * as it does for sort/shuffle.
 * @param mixed[] $arr
 * @param int[]   $perm
 */
function __mc_multisort_apply(array &$arr, array $perm): void
{
    // `array_keys` + an `$arr[$k]` re-read, not a rebuilt `$vals` list: this is
    // array_splice's proven shape. A `$keys[] = $k` rebuild types its element
    // from the first store, so a cell key loses its tag and `is_string($k)`
    // came back false for every entry (php keeps STRING keys and re-indexes
    // numeric ones, so that silently renumbered them all).
    $keys = array_keys($arr);
    /** @var array<array-key, mixed> $out */
    $out = [];
    foreach ($perm as $p) {
        $k = $keys[$p];
        if (\is_string($k)) { $out[$k] = $arr[$k]; } else { $out[] = $arr[$k]; }
    }
    $arr = $out;
}

/**
 * `array_all(a, predicate)` — true when every value satisfies `$predicate`.
 * The empty array is vacuously true, as in PHP 8.4.
 */
function array_all(array $a, callable $predicate): bool
{
    foreach ($a as $value) {
        if (!$predicate($value)) { return false; }
    }
    return true;
}

/**
 * `array_any(a, predicate)` — true when at least one value satisfies
 * `$predicate`; false for an empty array.
 */
function array_any(array $a, callable $predicate): bool
{
    foreach ($a as $value) {
        if ($predicate($value)) { return true; }
    }
    return false;
}

/** Return the first value accepted by `$predicate`, or null when absent. */
function array_find(array $a, callable $predicate): mixed
{
    foreach ($a as $value) {
        if ($predicate($value)) { return $value; }
    }
    return null;
}

/** Return the first key accepted by `$predicate`, or null when absent. */
function array_find_key(array $a, callable $predicate): int|string|null
{
    // Pull keys through array_keys: foreach's native key channel can carry a
    // raw string pointer, whereas array_keys returns a correctly boxed key.
    // TODO(foreach key channel): remove once InferTypes stamps the key-is-cell
    // verdict on the Foreach_ node and EmitLlvmControl reads the stamp instead
    // of re-deriving it.
    $keys = array_keys($a);
    foreach ($keys as $key) {
        $value = $a[$key];
        if ($predicate($value)) { return $key; }
    }
    return null;
}

/**
 * `array_change_key_case(a, case)` — transform string keys only. CASE_LOWER is
 * 0 and CASE_UPPER is 1; numeric keys remain untouched.
 */
function array_change_key_case(array $a, int $case = 0): array
{
    $out = [];
    // Iterate boxed keys — see array_find_key.
    $keys = array_keys($a);
    foreach ($keys as $key) {
        $value = $a[$key];
        if (is_string($key)) {
            // Keep the converted result in a string-typed local. Reassigning
            // `$key` (an int|string cell) loses the string representation
            // before the array-store lowering sees it.
            // TODO(cell repr): drop the extra local once a name assigned two
            // distinct scalar representations rides a cell for its whole life.
            if ($case === 1) {
                $converted = strtoupper($key);
            } else {
                $converted = strtolower($key);
            }
            $out[$converted] = $value;
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}
