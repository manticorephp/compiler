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
 * `array_splice(&$arr, offset, length, replacement)` — remove `$length` entries
 * at `$offset` (negative counts from the end; null length ⇒ to the end), splice
 * in `$replacement`, REINDEX. Returns the removed slice. List semantics; numeric
 * keys are renumbered as PHP does.
 * @param mixed[] $arr
 * @return mixed[]
 */
function array_splice(array &$arr, int $offset, ?int $length = null, mixed $replacement = []): array
{
    $vals = array_values($arr);
    $n = count($vals);
    if ($offset < 0) { $offset = $n + $offset; if ($offset < 0) { $offset = 0; } }
    elseif ($offset > $n) { $offset = $n; }
    if ($length === null) { $len = $n - $offset; }
    elseif ($length < 0) { $len = $n + $length - $offset; if ($len < 0) { $len = 0; } }
    else { $len = $length; }
    if ($offset + $len > $n) { $len = $n - $offset; }
    if (!\is_array($replacement)) { $replacement = [$replacement]; }
    $removed = [];
    $i = $offset;
    while ($i < $offset + $len) { $removed[] = $vals[$i]; $i = $i + 1; }
    $new = [];
    $i = 0;
    while ($i < $offset) { $new[] = $vals[$i]; $i = $i + 1; }
    foreach ($replacement as $rv) { $new[] = $rv; }
    $i = $offset + $len;
    while ($i < $n) { $new[] = $vals[$i]; $i = $i + 1; }
    $arr = $new;
    return $removed;
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
