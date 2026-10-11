<?php
// var_dump of a Closure segfaults
$cb = function (int $x): int { return $x + 1; };
ob_start();
var_dump($cb);
$out = ob_get_clean();
echo str_starts_with($out, 'object(Closure)#') ? "dumped\n" : "bad\n";
