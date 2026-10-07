<?php
// A @var-annotated Map statement truncates an unrelated int above 2^47 to 48 bits.
// issue: #131
use Manticore\Ds\Map;
function rep(int $t0): void { echo $t0, "\n"; }
$big = 300000000000000;
/** @var Map<string,int> $m */
$t = $big; $m = new Map();
$m->set("a", 1);
rep($t);
