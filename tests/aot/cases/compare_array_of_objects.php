<?php
// Typed arrays of objects compare their objects BY VALUE (php's object compare),
// not by identity: [$x] == [$y] for two equal instances, <=>, sort, max,
// in_array. Zend oracle.
final class P { public function __construct(public int $a, public string $b) {} }
$x = new P(1, 'q'); $y = new P(1, 'q'); $z = new P(2, 'q');
var_dump([$x] == [$y], [$x] != [$y], ['k' => $x] == ['k' => $y], [$x] == [$z], [$x] === [$y], [$x] === [$x]);
var_dump([$x] <=> [$z], [$z] <=> [$x], [$x] <=> [$y], [$x] < [$z]);
$l = [[$z], [$x]]; sort($l); echo $l[0][0]->a, "\n";
var_dump(max([$x], [$z])[0]->a, in_array([$y], [[$x]]));
$n = [[$x, 1], [$y, 1]]; var_dump($n[0] == $n[1]);
var_dump(['p' => [$x]] == ['p' => [$y]]);
