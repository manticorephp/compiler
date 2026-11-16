<?php
// An erased-receiver write of a property name only another class declares, into an object of a class without a dynamic-property bag, stores at stdClass bag offset 16 inside the object and crashes
// issue: #706
#[AllowDynamicProperties]
class HasA { public int $a = 1; }
class NoBag { public int $x = 10; public string $y = 'keep'; }
function put(mixed $o): void { $o->a = 5; }
$n = new NoBag();
try { put($n); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
put(new HasA());
var_dump($n->x, $n->y);
