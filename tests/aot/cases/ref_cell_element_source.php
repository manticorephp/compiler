<?php

// `[&$a[$k]]` — a STORABLE reference to an array element. The element is not
// addressed in place (the buffer relocates on the next insert); it is moved
// into an off-buffer box and the slot holds cell(REF, box). Every shape here
// is measured against the interpreter.

function mk(mixed $v): array { return ['k' => $v, 'j' => 2]; }
$refs = mk(1);
$pool = [&$refs['k'], 5];
$pool[0] = 99;
var_dump($refs['k']);
$refs['k'] = 7;
var_dump($pool[0]);

// The buffer moves under 2000 inserts; the stored reference must not.
for ($i = 0; $i < 2000; $i++) { $refs['n' . $i] = $i; }
$pool[0] = 123;
var_dump($refs['k']);
$refs['k'] = 456;
var_dump($pool[0]);

// Two references to one element share ONE box.
$pool2 = [&$refs['k']];
$pool2[0] = 789;
var_dump($pool[0], $refs['k']);

// An absent key comes into being as null, as php's does.
$pool3 = [&$refs['zz']];
var_dump($refs['zz']);
$pool3[0] = 'made';
var_dump($refs['zz']);

// An int key on a pure int-keyed array (an int key ADDED to a string-keyed
// hashed array makes foreach yield no keys at all today — a pre-existing bug
// unrelated to references), and the reference read back through every walker.
function mkl(mixed $v): array { return [$v, 2, 3]; }
$list = mkl(0);
$p4 = [&$list[0]];
$p4[0] = 'ZERO';
echo implode(',', $list), "\n";
foreach ($list as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";
echo json_encode($list), "\n";

// A `mixed` base with a CELL key (int-or-string at runtime): the deepclone
// shape, `$refs = $values` off an untyped parameter, keyed by a foreach.
function collect(mixed $values, array &$pool): array
{
    $refs = $values;
    foreach ($values as $k => $value) {
        $pool[] = [&$refs[$k], $value, &$value];
    }
    return $refs;
}
$p = [];
$r = collect(['a' => 1, 7 => 'seven'], $p);
var_dump(count($p));
$p[0][0] = 'A!';
$p[1][0] = 'SEVEN!';
var_dump($r['a'], $r[7]);

// `[$v, &$v]` — a by-value read AND a reference to the same foreach variable
// in one literal. The loop's store must go THROUGH the variable's box.
$out = [];
foreach ([10, 20] as $v) { $out[] = [$v, &$v]; }
var_dump($out[0][0], $out[1][0]);
$out[1][1] = 99;
var_dump($v);
