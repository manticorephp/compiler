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
 * array_splice_fn · array_ucompare_family · array_random_fns):
 * array_diff_assoc · array_intersect_assoc · array_diff_ukey ·
 * array_intersect_ukey · array_udiff · array_uintersect · array_udiff_assoc ·
 * array_uintersect_assoc · array_diff_uassoc · array_intersect_uassoc ·
 * array_udiff_uassoc · array_uintersect_uassoc · array_walk (by-value AND
 * `&$value`) · array_walk_recursive · array_splice · shuffle · array_rand ·
 * array_all · array_any · array_find · array_find_key · array_change_key_case.
 *
 * NOT HERE, blocked on a named compiler root cause:
 *  - array_replace_recursive / array_merge_recursive — the RECURSIVE self-call
 *    re-enters with a cell-typed arg (`$out[$k]` is a cell holding an array), so
 *    Monomorphize's callKey is '' and the erased body runs for every nested
 *    level. Rebuilding both sides into literal-bound locals first fixes it in a
 *    USER function but not in a PRELUDE one: there the rebuild buffer `$out`
 *    still binds `vec[unknown]` even though scanAssocLocals marks it assoc and
 *    the empty-literal retype fires (verified by instrumenting inferStoreLocal —
 *    a later pass re-stamps the binding), so a string key lands under a
 *    positional index. That is the vec/assoc widening root cause, not these two
 *    functions. Do NOT re-attempt them before it lands.
 *  - array_multisort — variadic BY-REF parallel arrays with interleaved SORT_*
 *    flags; needs by-ref variadic packs first.
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
