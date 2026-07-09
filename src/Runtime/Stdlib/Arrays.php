<?php

/**
 * Pure-PHP implementations of PHP array std functions on top of the
 * compiler's inline vec/assoc primitives. Lives in the global
 * namespace so user code calling `in_array(...)` resolves directly.
 *
 * Where these still hit a compiler-emitted builtin path
 * (`tryCompileBuiltin`), that path wins — these only fire when the
 * compiler hands the call through as a regular user function.
 */

/**
 * `in_array` — today only the string-needle / string-haystack shape
 * is fully supported (the only one the bootstrap compiler exercises).
 *
 * `@param mixed[]` (NOT string[]) so the foreach value `$v` is a CELL, not a
 * string — otherwise `$v === $needle` compiles as a string-vs-cell compare and
 * a non-string cell value (null/int) mis-matches. A concrete haystack still
 * routes through the InlineClosures synthesis (this fallback is cell-only).
 *
 * @param mixed[] $haystack
 */
function in_array(mixed $needle, array $haystack, bool $strict = false): bool
{
    // Non-concrete fallback (a CONCRETE haystack routes through the precise
    // InlineClosures synthesis). `mixed` needle + bare-`array` haystack keep the
    // values as cells, so `==`/`===` dispatch by tag — correct for a
    // heterogeneous (null/int/string/…) haystack, not just strings (the old
    // `strcmp((string)$v, …)` faulted on a non-string cell value).
    foreach ($haystack as $v) {
        if ($strict) {
            if ($v === $needle) { return true; }
        } else {
            if ($v == $needle) { return true; }
        }
    }
    return false;
}

/**
 * `array_search` — non-concrete fallback (the InlineClosures per-call synthesis
 * only fires on a CONCRETE haystack). String-needle / value shape: both sides go
 * through libc strcmp so bytes are compared, not pointers. Returns the matching
 * key or false. Mirrors {@see in_array}.
 *
 * `mixed` needle + bare-`array` haystack keep values as cells so `==`/`===`
 * dispatch by tag — a non-concrete ASSOC (string-key result) and STRICT search
 * both work now; a concrete haystack still routes through the InlineClosures
 * synthesis. Returns the matching key (int or string) or false.
 *
 * @param mixed[] $haystack  (cell values → tag-dispatched compare; see in_array)
 * @return int|string|false
 */
function array_search(mixed $needle, array $haystack, bool $strict = false): int|string|false
{
    foreach ($haystack as $k => $v) {
        if ($strict) {
            if ($v === $needle) { return $k; }
        } else {
            if ($v == $needle) { return $k; }
        }
    }
    return false;
}

function array_key_exists(int|string $key, array $arr): bool
{
    foreach ($arr as $k => $_) {
        if ($k === $key) { return true; }
    }
    return false;
}

function array_keys(array $arr): array
{
    $out = [];
    foreach ($arr as $k => $_) {
        $out[] = $k;
    }
    return $out;
}

function array_values(array $arr): array
{
    $out = [];
    foreach ($arr as $v) {
        $out[] = $v;
    }
    return $out;
}

function array_merge(array ...$arrays): array
{
    $out = [];
    foreach ($arrays as $arr) {
        foreach ($arr as $k => $v) {
            if (\is_int($k)) {
                $out[] = $v;
            } else {
                $out[$k] = $v;
            }
        }
    }
    return $out;
}

// array_slice / array_map / array_filter live in prelude/array_fns.php
// (PRELUDE-injected), NOT here: as a stdlib extern the `array` param's element
// erases to unknown, so re-stored values read back as garbage (a raw int → a
// denormal float under NaN-boxing) and a callback crosses the closure ABI. In
// the prelude, call-site element inference types the param + result. Same reason
// as array_reverse / array_reduce / sort / usort. Gated in Main.

function reset(array &$arr): mixed
{
    foreach ($arr as $v) { return $v; }
    return false;
}

function end(array &$arr): mixed
{
    $last = false;
    foreach ($arr as $v) { $last = $v; }
    return $last;
}

/**
 * `array_is_list` — true iff `$a`'s keys are the integers 0..n-1 in order
 * (an empty array is a list). A cell key is cast to int for the index check
 * to avoid a cell-vs-int strict compare.
 *
 * @param mixed[] $a
 */
function array_is_list(array $a): bool
{
    $i = 0;
    foreach ($a as $k => $v) {
        if (!is_int($k) || (int)$k !== $i) { return false; }
        $i = $i + 1;
    }
    return true;
}

/**
 * Return the values of `$a` in reverse order, reindexed from 0 (1:1 with
 * PHP `array_reverse` for a positional list).
 *
 * @param mixed[] $a
 * @return mixed[]
 */
// `array_reverse` / `array_pad` deliberately omitted here: as stdlib externs
// their `array` param's element erases to unknown, so they re-store RAW
// elements into the result. Under canonical NaN-boxing a raw int (0 header) is
// misread as a double — a 1-arg copy no longer survives the way it did when
// tag-0 meant int. They live in the PRELUDE (`prelude/array_fns.php`), injected
// when the program names them, where call-site element inference types the
// param and the result rides the element type. Mirrors usort / array_reduce.

// `array_key_last` deliberately omitted: PHP returns int|string|null,
// our compiler doesn't yet track that union, and the call sites that
// matter (Compile\Compiler::emitDispatchChain) want an int key. They
// can be rewritten in PHP to use a `foreach (... as $k => $_) { $last
// = $k; }` loop when the time comes. Bringing back the helper with a
// fixed return type would silently break those call sites.

/**
 * `range(start, end, step)` — an inclusive list of integers ascending or
 * descending (step is taken as its magnitude). Float ranges round to int here.
 */
function range(int $start, int $end, int $step = 1): array
{
    $out = [];
    $s = $step < 0 ? -$step : $step;
    if ($s === 0) { $s = 1; }
    if ($start <= $end) {
        for ($i = $start; $i <= $end; $i = $i + $s) { $out[] = $i; }
    } else {
        for ($i = $start; $i >= $end; $i = $i - $s) { $out[] = $i; }
    }
    return $out;
}

/**
 * `array_fill(start, count, value)` — `count` copies of `value` keyed from
 * `start` (non-negative start → a positional list).
 *
 * @param mixed $value
 */
function array_fill(int $start, int $count, mixed $value): array
{
    $out = [];
    $i = 0;
    while ($i < $count) {
        $out[$start + $i] = $value;
        $i = $i + 1;
    }
    return $out;
}

// usort / array_reduce / array_flip / array_combine / array_count_values are
// NOT defined here on purpose. As stdlib externs their `array` param's element
// erases to unknown, so a dynamic callback receives a RAW value while an
// untyped closure param is a cell → a tag-dispatch on raw bits crashes (a
// 2-arg callback / mixed fold-carry; a 1-arg int callback only survives because
// a small int's tag is 0). The fix is to PRELUDE-INJECT these (compile them with
// the user program like the SPL classes), where call-site element inference
// types the param and the in-module closure ABI matches. Follow-up.

/**
 * Return the values of a single column `$column_key` from a list of array rows,
 * optionally re-keyed by each row's `$index_key` (PHP `array_column`). A null
 * `$column_key` yields whole rows (re-keyed). Rows lacking the column are
 * skipped. Array rows only (the object-property form is not modelled here).
 * @param array<int|string, array<int|string, mixed>> $array
 */
function array_column(array $array, int|string|null $column_key, int|string|null $index_key = null): array
{
    $out = [];
    foreach ($array as $row) {
        if ($column_key === null) {
            $val = $row;
        } else {
            if (!isset($row[$column_key])) { continue; }
            $val = $row[$column_key];
        }
        if ($index_key !== null && isset($row[$index_key])) {
            $out[$row[$index_key]] = $val;
        } else {
            $out[] = $val;
        }
    }
    return $out;
}
