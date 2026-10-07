<?php
// An array emptied by unset() keeps its entries as tombstones until the next
// compaction; every emptiness test must read the LIVE count, not the length
// word (`if (!$list) unset(...)` in symfony's EventDispatcher never fired).
function m(mixed $m): void {
    var_dump(!$m, (bool)$m, empty($m), $m ? 1 : 0, (int)(bool)$m, $m == [], $m == false, $m != false, (float)!$m);
}
$a = ['x'];
unset($a[0]);
var_dump(!$a, (bool)$a, empty($a), $a ? 'y' : 'n', $a == [], $a === [], $a != [], count($a));
if ($a) { echo "T\n"; } else { echo "F\n"; }
echo $a ?: 'elvis', "\n";
m($a);
$h = ['k' => 1, 'j' => 2];
unset($h['k'], $h['j']);
var_dump(!$h, empty($h), $h == []);
m($h);
final class P { public array $p = [1]; }
$o = new P();
unset($o->p[0]);
var_dump(!$o->p, empty($o->p));
$n = ['a' => ['b' => 1]];
unset($n['a']['b']);
var_dump(!$n['a'], empty($n['a']), !$n);
$c = [[5 => 'a']];
foreach ($c as $i => &$l) {
    foreach ($l as $k => $v) { unset($l[$k]); }
    var_dump(!$l, count($l));
}
unset($l);
$p = [1, 2, 3];
unset($p[0]);
var_dump(array_first($p), array_key_first($p), array_last($p), array_key_last($p));
foreach ([0, null, '', '0', [], 1, 'a', [1]] as $x) { m($x); }
