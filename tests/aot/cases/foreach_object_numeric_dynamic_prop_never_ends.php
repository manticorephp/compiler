<?php
// foreach over an object with a numeric dynamic property ('5') yields 0=0, 1=0, ... and never terminates.
#[AllowDynamicProperties]
final class P { public int $a = 1; }
$p = new P();
$p->{'5'} = 'five';
$n = 0;
foreach ($p as $k => $v) {
    echo $k, '=', $v, "\n";
    if (++$n >= 10) { echo "stopped after 10\n"; break; }
}
echo "count $n\n";
$o = (object) ['x' => 1];
$o->{'7'} = 'seven';
$n = 0;
foreach ($o as $k => $v) {
    echo $k, '=', $v, "\n";
    if (++$n >= 10) { echo "stopped after 10\n"; break; }
}
echo "count $n\n";
