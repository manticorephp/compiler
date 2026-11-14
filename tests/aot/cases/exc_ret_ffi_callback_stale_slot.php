<?php
// After a PHP callback threw inside qsort, the later comparator calls must not see that exception: a try/catch in them stays silent and the PHP caller still gets it.

#[\Ffi\Library('c'), \Ffi\Symbol('malloc')]
function xc_malloc(#[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function xc_free(\Ffi\Ptr $p): void {}

#[\Ffi\Library('c'), \Ffi\Symbol('qsort')]
function xc_qsort(\Ffi\Ptr $base, #[\Ffi\CType('size_t')] int $nmemb,
                  #[\Ffi\CType('size_t')] int $size, \Ffi\Ptr $compar): void {}

final class Calls { public static int $n = 0; }

function xc_check(int $n): int
{
    if ($n < 0) { throw new LogicException("neg"); }
    return $n;
}

function xc_cmp(\Ffi\Ptr $a, \Ffi\Ptr $b): int
{
    Calls::$n = Calls::$n + 1;
    if (Calls::$n === 3) { throw new RuntimeException('cmp ' . Calls::$n); }
    try { xc_check(Calls::$n); } catch (RuntimeException $e) { echo "stale: ", $e->getMessage(), "\n"; }
    return \peek_i64($a, 0) <=> \peek_i64($b, 0);
}

function xc_sort_marks(\Ffi\Ptr $cmp): string
{
    $buf = \xc_malloc(8 * 8);
    for ($i = 0; $i < 8; $i++) { \poke_i64($buf, $i * 8, (8 - $i) * 3); }
    \xc_qsort($buf, 8, 8, $cmp);
    echo "tail ran\n";
    $tail = 'tail-ran';
    \xc_free($buf);
    return $tail;
}

$cmp = \fn_to_ptr('xc_cmp');
try {
    echo \xc_sort_marks($cmp), "\n";
} catch (RuntimeException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo "after\n";
