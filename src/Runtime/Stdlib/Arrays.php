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

// array_merge / array_slice / array_map / array_filter live in prelude/array_fns.php
// (PRELUDE-injected), NOT here: as a stdlib extern the `array` param's element
// erases to unknown, so re-stored values read back as garbage (a raw int → a
// denormal float under NaN-boxing) and a callback crosses the closure ABI. In
// the prelude, call-site element inference types the param + result. Same reason
// as array_reverse / array_reduce / sort / usort. Gated in Main.

// `reset` / `end` are NOT here any more, and neither are `current` / `key` /
// `next` / `prev`: the whole internal-pointer family is a CODEGEN BUILTIN
// (EmitLlvmBuiltins::biArrayCursor). They read and write php's cursor in the
// array header (flags bits 36-63), which a PHP-level helper cannot reach — and
// the old walking `reset`/`end` could not move the cursor at all, so a
// following `current()` disagreed with them.

// current() / key() answer from the FIRST entry, the same simplification
// reset() and end() already make: an array here carries no internal pointer, so
// there is no cursor for next()/prev() to move. That covers the overwhelmingly
// common `key($arr)` / `current($arr)` on a freshly built array (symfony reads
// `key(class_implements($x))` exactly so) and is honest about the rest.
function current(array &$arr): mixed
{
    foreach ($arr as $v) { return $v; }
    return false;
}

function key(array &$arr): mixed
{
    foreach ($arr as $k => $v) { return $k; }
    return null;
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
 * `range(start, end, step)` — an inclusive list ascending or descending (the
 * step is taken as its magnitude).
 *
 * Three shapes, as in PHP: INT when both ends and the step are integral, FLOAT
 * when any of them is not, and CHARACTER when both ends are single-character
 * non-numeric strings (`range('a','e')`). The float branch counts iterations
 * up front rather than accumulating `$i += $step`, so rounding drift cannot
 * add or drop a final element.
 */
function range(int|float|string $start, int|float|string $end, int|float $step = 1): array
{
    if (\is_string($start) && \is_string($end)
        && \strlen($start) === 1 && \strlen($end) === 1
        && !\is_numeric($start) && !\is_numeric($end)) {
        return __mc_range_char($start, $end, (int)$step);
    }
    $fs = (float)$start;
    $fe = (float)$end;
    $fstep = (float)$step;
    if ($fstep < 0) { $fstep = -$fstep; }
    if ($fstep === 0.0) { $fstep = 1.0; }
    $isFloat = !__mc_range_integral($start) || !__mc_range_integral($end)
        || (float)(int)$fstep !== $fstep;
    if (!$isFloat) {
        $out = [];
        $s = (int)$fstep;
        $a = (int)$fs;
        $b = (int)$fe;
        if ($a <= $b) {
            for ($i = $a; $i <= $b; $i = $i + $s) { $out[] = $i; }
        } else {
            for ($i = $a; $i >= $b; $i = $i - $s) { $out[] = $i; }
        }
        return $out;
    }
    $span = $fs <= $fe ? $fe - $fs : $fs - $fe;
    $n = (int)($span / $fstep);
    $out = [];
    $i = 0;
    while ($i <= $n) {
        $out[] = $fs <= $fe ? $fs + $fstep * $i : $fs - $fstep * $i;
        $i = $i + 1;
    }
    return $out;
}

/** Whether a range endpoint is an integer value (an int, or a float with no
 *  fractional part — `range(1.0, 3.0)` is an INT range in PHP). */
function __mc_range_integral(int|float|string $v): bool
{
    if (\is_int($v)) { return true; }
    if (\is_float($v)) { return (float)(int)$v === $v; }
    return (string)(int)$v === $v;
}

/** `range('a', 'e')` — the character form, ascending or descending. */
function __mc_range_char(string $start, string $end, int $step): array
{
    $a = \ord($start);
    $b = \ord($end);
    $s = $step < 0 ? -$step : $step;
    if ($s === 0) { $s = 1; }
    $out = [];
    if ($a <= $b) {
        for ($i = $a; $i <= $b; $i = $i + $s) { $out[] = \chr($i); }
    } else {
        for ($i = $a; $i >= $b; $i = $i - $s) { $out[] = \chr($i); }
    }
    return $out;
}

/**
 * `count($x, COUNT_RECURSIVE)` — every element, plus every element of every
 * nested array, at any depth. `count` itself is a codegen builtin that reads the
 * live length straight out of the header and ignores a mode argument, so
 * LowerExprs rewrites the non-zero-mode call into this instead of growing the
 * builtin.
 *
 * `$arr` is `mixed`, NOT `array`: a foreach over a bare-`array` stdlib param
 * leaves the values RAW, and this needs the tag to ask `is_array`. A cell
 * subject yields cell values and recurses on the tag alone — no element repr is
 * ever needed, which is why this one CAN live in the stdlib .o.
 */
function __mc_count_recursive(mixed $arr): int
{
    $n = 0;
    foreach ($arr as $v) {
        $n = $n + 1;
        if (\is_array($v)) { $n = $n + __mc_count_recursive($v); }
    }
    return $n;
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
 * Return the values of a single column `$column_key` from a list of rows,
 * optionally re-keyed by each row's `$index_key` (PHP `array_column`). A null
 * `$column_key` yields whole rows (re-keyed). Rows lacking the column are
 * skipped.
 *
 * ARRAY rows only. php also accepts OBJECT rows (reading a public property of
 * the same name), and that is deliberately NOT modelled here: inside this
 * function `$row` is erased to a cell, and a cell receiver cannot be reflected
 * on — `property_exists($row, …)` returns false and a dynamic `$row->$name`
 * read yields the cell's bits, even after funnelling through an `object`-typed
 * param. It needs a compiler fix (erased-receiver reflection), not a stdlib one;
 * shipping the object arm before that would silently drop every object row.
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

/**
 * The `array_keys($arr, $search_value, $strict)` form — the keys whose value
 * matches `$search_value`. Split out of the codegen builtin (which only handles
 * the all-keys form) and called by it with `$strict` always passed explicitly,
 * so no default-padding is needed at the call site.
 *
 * `$arr` is `mixed`, NOT `array`: a foreach over a bare-`array` param leaves the
 * VALUES raw (only the key is re-tagged), so `$v == $search` would compare a raw
 * element against a boxed needle by bits. A cell subject yields cell values, and
 * the builtin boxes the argument (rebuilding a raw vec into a cell vec) for it.
 * @return mixed[]
 */
function __mc_array_keys_search(mixed $arr, mixed $search, bool $strict): array
{
    /** @var mixed[] $out */
    $out = [];
    foreach ($arr as $k => $v) {
        if ($strict) {
            if ($v === $search) { $out[] = $k; }
        } else {
            if ($v == $search) { $out[] = $k; }
        }
    }
    return $out;
}

/**
 * `min($arr)` / `max($arr)` — the smallest / largest ELEMENT of a single array
 * argument (PHP's one-arg form; two or more operands compare against each other
 * in the codegen builtin instead). `$arr` is `mixed` so the foreach yields CELL
 * values — a bare-`array` param leaves them raw, and the comparison would read a
 * boxed element by bits. The builtin boxes the argument for it.
 *
 * The accumulator is seeded from the first ELEMENT, never from `null`: a
 * `null|T` local types as NON-null, so the return coercion boxed an
 * already-boxed cell a second time and var_dump printed the box's own bits.
 * @return mixed
 */
function __mc_minmax_of(mixed $arr, bool $isMax): mixed
{
    /** @var mixed[] $vals */
    $vals = [];
    foreach ($arr as $v) { $vals[] = $v; }
    $n = \count($vals);
    if ($n === 0) { return false; }
    $acc = $vals[0];
    for ($i = 1; $i < $n; $i = $i + 1) {
        $v = $vals[$i];
        if ($isMax) {
            if ($v > $acc) { $acc = $v; }
        } else {
            if ($v < $acc) { $acc = $v; }
        }
    }
    return $acc;
}

/**
 * The `+` array-union operator: every key of $a, then each key of $b that $a
 * does NOT already have (first-wins, and int keys are NOT renumbered — unlike
 * array_merge). A fresh array is built so neither operand is mutated.
 * @param array<int|string, mixed> $a
 * @param array<int|string, mixed> $b
 * @return array<int|string, mixed>
 */
function __mir_array_union(array $a, array $b): array
{
    $out = [];
    foreach ($a as $k => $v) { $out[$k] = $v; }
    foreach ($b as $k => $v) {
        if (!\array_key_exists($k, $out)) { $out[$k] = $v; }
    }
    return $out;
}
