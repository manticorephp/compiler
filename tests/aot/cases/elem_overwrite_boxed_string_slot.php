<?php
// Overwriting an element of a declared string[] whose buffer holds boxed cells (sort() of numeric-like strings out of a docblock-typed array<string, string[]>) releases the NaN-boxed word and segfaults.
/** @return array<string, string[]> */
function grp(string $s): array
{
    $out = [];
    foreach (explode('|', $s) as $p) {
        $out[substr($p, -1)][] = $p;
    }
    return $out;
}
$cli = grp('3a|2b|1a|0a');
$theirs = $cli['a'];
sort($theirs);
echo implode(',', $theirs), ' ', implode(',', $cli['a']), "\n";
$more = $cli['b'];
$more[] = '9b';
rsort($more);
echo implode(',', $more), ' ', implode(',', $cli['b']), "\n";
function inner(): void
{
    $cli = grp('3a|2b|1a|0a');
    $t = $cli['a'];
    usort($t, fn($x, $y) => $x <=> $y);
    array_walk($t, function (&$v) { $v = $v . '!'; });
    echo implode(',', $t), ' ', implode(',', $cli['a']), "\n";
}
inner();
class P { public function __construct(public string $n) {} }
/** @return array<string, P[]> */
function grpObj(string $s): array
{
    $out = [];
    foreach (explode('|', $s) as $p) {
        $out[substr($p, -1)][] = new P($p);
    }
    return $out;
}
/** @return array<string, int[][]> */
function grpArr(string $s): array
{
    $out = [];
    foreach (explode('|', $s) as $p) {
        $out[substr($p, -1)][] = [(int)$p];
    }
    return $out;
}
$objs = grpObj('3a|2b|1a|0a')['a'];
$objs[0] = new P('z');
usort($objs, fn($x, $y) => $x->n <=> $y->n);
unset($objs[1]);
echo implode(',', array_map(fn($p) => $p->n, $objs)), "\n";
$arrs = grpArr('3a|2b|1a|0a')['a'];
$arrs[0] = [9];
usort($arrs, fn($x, $y) => $x[0] <=> $y[0]);
unset($arrs[1]);
echo json_encode($arrs), "\n";
