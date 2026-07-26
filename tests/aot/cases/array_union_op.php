<?php
// PHP's `+` on arrays is the UNION operator: the LEFT side's keys always win
// and the right one only fills gaps. Nothing is renumbered (that is what makes
// it different from array_merge). Before this, `$a + $b` emitted `add i64
// ptr, ptr` and the result read back as a bogus int.
$a = ['x' => 1, 'y' => 2];
$b = ['y' => 99, 'z' => 3];
var_dump($a + $b);
var_dump($b + $a);

// Lists: keys are POSITIONS, so the longer side only contributes its tail.
$l1 = ['a', 'b'];
$l2 = ['A', 'B', 'C', 'D'];
print_r($l1 + $l2);
print_r($l2 + $l1);

// Empty operands on either side.
print_r([] + $l1);
print_r($l1 + []);
var_dump(count([] + []));

// Compound assignment goes through the same path.
$acc = ['k' => 'keep'];
$acc += ['k' => 'ignored', 'new' => 'added'];
print_r($acc);

// Mixed value types (cell elements) and a nested array element.
$m = ['s' => 'str', 'i' => 7];
$n = ['i' => 0, 'f' => 1.5, 'arr' => [1, 2]];
$u = $m + $n;
echo $u['s'], "|", $u['i'], "|", $u['f'], "|", $u['arr'][1], "\n";

// The operands must be untouched by the union (value semantics).
print_r($m);
print_r($n);

// Union of string- and int-keyed arrays keeps both key kinds.
$mixk = ['one' => 1, 5 => 'five'];
$mixk2 = [5 => 'IGNORED', 6 => 'six', 'two' => 2];
print_r($mixk + $mixk2);

// Feeding the result straight into a consumer, and unioning in a loop (the
// result is a fresh +1 array each time — it must be released, not leaked).
$total = 0;
for ($i = 0; $i < 3; $i++) {
    $step = ['n' => $i] + ['n' => 999, 'extra' => $i * 2];
    $total += $step['n'] + $step['extra'];
}
echo $total, "\n";
foreach (($a + $b) as $k => $v) { echo $k, "=", $v, " "; }
echo "\n";
echo count($a + $b), "\n";
