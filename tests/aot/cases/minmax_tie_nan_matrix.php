<?php
declare(strict_types=1);

// php's min()/max() over every operand pairing, both orders: a direct
// two-argument call is the FRAMELESS body (`max` keeps the left on `l >= r`,
// `min` on `l < r`: ties and NAN hand back the right), three or more and any
// spread are the variadic loop (int / double / `<=>` modes, strict wins only),
// one array is zend_hash_minmax (`winner <=> element`). Values are routed
// through untyped params so php does not constant-fold them.

function e(mixed $v): string
{
    if (\is_float($v)) { return \is_nan($v) ? 'NAN' : 'f' . \var_export($v, true); }
    if (\is_array($v)) { return '[' . \implode(',', \array_map('e', $v)) . ']'; }
    return \var_export($v, true);
}

function mn2(mixed $a, mixed $b): mixed { return min($a, $b); }
function mx2(mixed $a, mixed $b): mixed { return max($a, $b); }
function mn3(mixed $a, mixed $b, mixed $c): mixed { return min($a, $b, $c); }
function mx3(mixed $a, mixed $b, mixed $c): mixed { return max($a, $b, $c); }
function mna(mixed $a): mixed { return min($a); }
function mxa(mixed $a): mixed { return max($a); }
/** @param mixed[] $p */
function sp(array $p): string { return e(min(...$p)) . '/' . e(max(...$p)); }

$vals = [null, false, true, 0, '0', '5', 5, 5.0, 'abc', [], [1], NAN, '5.0', -1, 1.5, ''];
foreach ($vals as $x) {
    $row = [];
    $tri = '';
    foreach ($vals as $y) {
        $row[] = e(mn2($x, $y)) . '/' . e(mx2($x, $y)) . '/' . sp([$x, $y]);
        foreach ($vals as $z) {
            $tri .= e(mn3($x, $y, $z)) . e(mx3($x, $y, $z)) . e(mna([$x, $y, $z])) . e(mxa([$x, $y, $z])) . sp([$x, $y, $z]) . ';';
        }
    }
    echo e($x), ': ', \implode(' ', $row), ' #', \md5($tri), "\n";
}

// The inline paths: all floats (NAN on either side), all strings (numeric
// ties), all ints.
function ff(float $a, float $b): string { return e(min($a, $b)) . e(max($a, $b)); }
function fff(float $a, float $b, float $c): string { return e(min($a, $b, $c)) . e(max($a, $b, $c)); }
function ss(string $a, string $b): string { return e(min($a, $b)) . e(max($a, $b)); }
function sss(string $a, string $b, string $c): string { return e(min($a, $b, $c)) . e(max($a, $b, $c)); }
echo ff(NAN, 1.0), ' ', ff(1.0, NAN), ' ', ff(2.0, 2.0), ' ', ff(-0.0, 0.0), "\n";
echo fff(NAN, 1.0, 2.0), ' ', fff(1.0, NAN, 2.0), ' ', fff(2.0, 1.0, NAN), "\n";
echo ss('1', '01'), ' ', ss('01', '1'), ' ', ss('abc', 'abd'), ' ', sss('1', '01', '1.0'), ' ', sss('10', '9', 'a'), "\n";
echo min(3, 1, 2), max(3, 1, 2), min(4, 4), "\n";
try { echo min(...[]); } catch (\ArgumentCountError $ex) { echo $ex->getMessage(), "\n"; }
