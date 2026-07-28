<?php

// SORT_* flags across the sort family (a non-default flag routes through
// usort/uasort/uksort with __mc_sort_cmp), count(x, COUNT_RECURSIVE), and
// array_column. SORT_FLAG_CASE lowercases then strcmp rather than calling the
// libc strcasecmp bind, whose C-int return is not sign-extended in this tree.

// sort family with SORT_* flags.
$a = ['10', '9', '1'];
sort($a, SORT_STRING);
print_r($a);
$b = ['10', '9', '1'];
sort($b, SORT_NUMERIC);
print_r($b);
$c = ['10', '9', '1'];
rsort($c, SORT_STRING);
print_r($c);
$d = ['img12', 'img10', 'img2'];
sort($d, SORT_NATURAL);
print_r($d);
$e = ['B', 'a', 'C'];
sort($e, SORT_STRING | SORT_FLAG_CASE);
print_r($e);
$f = ['b' => '10', 'a' => '9'];
ksort($f, SORT_STRING);
print_r($f);
$g = ['b' => '10', 'a' => '9'];
krsort($g, SORT_STRING);
print_r($g);
$h = ['x' => '10', 'y' => '9'];
asort($h, SORT_NUMERIC);
print_r($h);
$i = ['x' => '10', 'y' => '9'];
arsort($i, SORT_NUMERIC);
print_r($i);

// Default (no flag) behaviour must be unchanged.
$j = [3, 1, 2];
sort($j);
print_r($j);
$k = ['b' => 2, 'a' => 1];
ksort($k);
print_r($k);

// count with COUNT_RECURSIVE.
var_dump(count([[1, 2], [3]], COUNT_RECURSIVE));
var_dump(count([[1, 2], [3]]));
var_dump(count([[1, 2], [3]], COUNT_NORMAL));
var_dump(count([1, [2, [3, [4]]]], COUNT_RECURSIVE));
var_dump(count([], COUNT_RECURSIVE));

// array_column over array rows (OBJECT rows are not modelled — see Arrays.php).
$arows = [['id' => 3, 'n' => 'c'], ['id' => 1, 'n' => 'a']];
print_r(array_column($arows, 'n'));
print_r(array_column($arows, 'n', 'id'));
print_r(array_column($arows, 'missing'));
print_r(array_column($arows, null, 'id'));
