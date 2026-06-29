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
 * Both sides go through libc strcmp so they actually compare bytes
 * rather than pointer identity.
 *
 * @param string[] $haystack
 */
function in_array(string $needle, array $haystack, bool $strict = false): bool
{
    foreach ($haystack as $v) {
        // The compiler infers each foreach value as ptr from the
        // `string[]` element-class hint, so strcmp is the right op
        // for both === and == in this signature.
        if (\Runtime\Libc\strcmp((string)$v, $needle) === 0) {
            return true;
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
 * LIMITATION: handles a vec (int-keyed) haystack only. A non-concrete ASSOC
 * (string-key result) and STRICT (===) search need a tagged cell-equality
 * compare the backend doesn't emit yet, so those are not supported here — a
 * concrete haystack still routes through the precise InlineClosures synthesis.
 *
 * @param string[] $haystack
 * @return int|false
 */
function array_search(string $needle, array $haystack, bool $strict = false): int|false
{
    foreach ($haystack as $k => $v) {
        if (\Runtime\Libc\strcmp((string)$v, $needle) === 0) {
            return $k;
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

function array_slice(array $arr, int $offset, ?int $length = null): array
{
    $count = \count($arr);
    if ($offset < 0) { $offset = \max(0, $count + $offset); }
    if ($length === null) {
        $end = $count;
    } elseif ($length < 0) {
        $end = \max($offset, $count + $length);
    } else {
        $end = \min($count, $offset + $length);
    }
    $out = [];
    $i = 0;
    foreach ($arr as $v) {
        if ($i >= $end) { break; }
        if ($i >= $offset) { $out[] = $v; }
        $i = $i + 1;
    }
    return $out;
}

// array_map / array_filter live in prelude/array_fns.php (PRELUDE-injected),
// NOT here: a callback invoked across the separately-linked stdlib.o boundary
// crashes (the closure ABI + bare-array element erasure). Same reason as
// array_reduce / sort / usort. Gated in Main on `array_map(` / `array_filter(`.

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
