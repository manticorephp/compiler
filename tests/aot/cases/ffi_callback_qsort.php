<?php

// C calls back into PHP: libc's qsort driving a PHP comparator.
//
// qsort is the cleanest possible proof — pure libc, no new dependency, and it
// exercises the whole path: C takes the address of a compiled PHP function,
// calls it many times on its own stack, and reads our i64 return as an `int`.

#[\Ffi\Library('c'), \Ffi\Symbol('malloc')]
function cb_malloc(#[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function cb_free(\Ffi\Ptr $p): void {}

#[\Ffi\Library('c'), \Ffi\Symbol('qsort')]
function cb_qsort(\Ffi\Ptr $base, #[\Ffi\CType('size_t')] int $nmemb,
                  #[\Ffi\CType('size_t')] int $size, \Ffi\Ptr $compar): void {}

/** `int (*)(const void *, const void *)` — ascending by the i64 each points at. */
function cb_cmp_asc(\Ffi\Ptr $a, \Ffi\Ptr $b): int
{
    $x = \peek_i64($a, 0);
    $y = \peek_i64($b, 0);
    if ($x < $y) { return -1; }
    if ($x > $y) { return 1; }
    return 0;
}

/** The same, reversed — proves the address is really per-function. */
function cb_cmp_desc(\Ffi\Ptr $a, \Ffi\Ptr $b): int
{
    return -\cb_cmp_asc($a, $b);
}

/** @param int[] $vals */
function cb_sorted(array $vals, \Ffi\Ptr $cmp): string
{
    $n = \count($vals);
    $buf = \cb_malloc($n * 8);
    $i = 0;
    foreach ($vals as $v) {
        \poke_i64($buf, $i * 8, $v);
        $i = $i + 1;
    }
    \cb_qsort($buf, $n, 8, $cmp);
    $out = '';
    for ($j = 0; $j < $n; $j = $j + 1) {
        if ($j > 0) { $out = $out . ','; }
        $out = $out . \peek_i64($buf, $j * 8);
    }
    \cb_free($buf);
    return $out;
}

$vals = [42, 7, 1000, -3, 0, 7, 99];
echo "asc:  ", \cb_sorted($vals, \fn_to_ptr('cb_cmp_asc')), "\n";
echo "desc: ", \cb_sorted($vals, \fn_to_ptr('cb_cmp_desc')), "\n";

// A callback invoked many times: the C frame is re-entered per comparison, so a
// leak or a clobbered arena would show up as a wrong order or a crash.
$big = [];
for ($k = 0; $k < 400; $k = $k + 1) {
    $big[] = (($k * 7919) % 1009) - 500;
}
$s = \cb_sorted($big, \fn_to_ptr('cb_cmp_asc'));
$parts = \explode(',', $s);
$ok = true;
for ($k = 1; $k < \count($parts); $k = $k + 1) {
    if ((int) $parts[$k - 1] > (int) $parts[$k]) { $ok = false; }
}
echo "n=", \count($parts), " ordered=", $ok ? 'yes' : 'no', "\n";
echo "min=", $parts[0], " max=", $parts[\count($parts) - 1], "\n";
