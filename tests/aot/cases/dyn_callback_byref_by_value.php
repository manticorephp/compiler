<?php
// An internal function hands its callback VALUES: a by-reference comparator
// named by string gets php's "must be passed by reference, value given" —
// a warning in Zend, an Error here. By-value names and array_walk (which
// does pass a reference) keep working.
function cmpRef(int &$a, int &$b): int { return $a <=> $b; }
function cmpVal(int $a, int $b): int { return $b <=> $a; }
function cmpOptRef(int $a, int $b, mixed &$unused = null): int { return $a <=> $b; }
function addOne(int &$v, int $k, mixed $extra): void { $v += 1; }

$a = [3, 1, 2];
// Names known only at run time: a literal would be resolved at compile time.
$ref = $argc > 9 ? '' : 'cmpRef';
$val = $argc > 9 ? '' : 'cmpVal';
$add = $argc > 9 ? '' : 'addOne';
$opt = $argc > 9 ? '' : 'cmpOptRef';
try {
    usort($a, $ref);
    echo "sorted\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    uasort($a, $ref);
    echo "sorted\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
usort($a, $opt);
echo implode(',', $a), "\n";
usort($a, $val);
echo implode(',', $a), "\n";
array_walk($a, $add);
echo implode(',', $a), "\n";
