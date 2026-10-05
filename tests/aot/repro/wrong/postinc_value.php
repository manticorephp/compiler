<?php
// Post-increment used as a value yields the NEW value (property and plain local)
// issue: #56
final class O { public int $n = 1; }
$o = new O();
$x = $o->n++;
echo $x, " ", $o->n, "\n";
$l = 5;
$y = $l++;
echo $y, " ", $l, "\n";
