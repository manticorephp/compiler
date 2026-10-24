<?php
// Closure::bind does not co-own the new $this: it is freed with the last outside owner.
class Box { private int $v = 42; }
function mk() { $read = function(): int { return $this->v; }; $o = new Box(); $b = Closure::bind($read, $o, Box::class); unset($o); return $b; }
$b = mk();
$x = [str_repeat("z", 64), str_repeat("y", 64)];
echo $b(), "\n";
