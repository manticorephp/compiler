<?php
// A by-value foreach iterates a SNAPSHOT in php: inserting into the array being
// walked never extends the walk. Here the insert also RELOCATES the entry buffer
// (grow, packed->hashed promotion, tombstone compaction), so without the copy the
// loop walks freed memory.

$b = [];
for ($i = 0; $i < 8; $i++) {
    $b['k' . $i] = $i;
}
$n = 0;
foreach ($b as $k => $v) {
    if ($n < 4) {
        $b['x' . $n] = 100 + $n;
    }
    $n++;
    echo $k, '=', $v, ' ';
}
echo "\n", implode(',', array_keys($b)), "\n";

// Same, with holes already in the buffer.
$h = [];
for ($i = 0; $i < 8; $i++) {
    $h['k' . $i] = $i;
}
unset($h['k1'], $h['k3'], $h['k5']);
$seen = [];
$m = 0;
foreach ($h as $k => $v) {
    $seen[] = $k . '=' . $v;
    if ($m < 4) {
        $h['x' . $m] = 100 + $m;
    }
    $m++;
}
echo implode(' ', $seen), "\n";
echo implode(',', array_keys($h)), "\n";

// Append into an int-keyed base.
$v = [1, 2, 3, 4];
$c = 0;
foreach ($v as $k => $e) {
    if ($c < 3) {
        $v[] = 100 + $c;
    }
    $c++;
    echo $k, ':', $e, ' ';
}
echo "\n", count($v), "\n";

// A BY-REF foreach must keep writing through to the real buffer.
$r = ['a' => 1, 'b' => 2, 'c' => 3];
foreach ($r as $k => &$val) {
    $val *= 10;
}
unset($val);
echo implode(',', $r), "\n";

// unset() of the base's own element inside the walk (the shape that already
// took the snapshot) still sees every original entry.
$u = ['p' => 1, 'q' => 2, 'r' => 3];
foreach ($u as $k => $val) {
    unset($u[$k]);
    echo $k, '#', $val, ' ';
}
echo "\n", count($u), "\n";
