<?php
// A string from json_decode passed to an int parameter under strict_types does not throw TypeError.
// issue: #84
declare(strict_types=1);
function f(int $x) { return $x; }
$a = json_decode('["x"]');
try { f($a[0]); echo "noerr\n"; } catch (Throwable $e) { echo get_class($e), "\n"; }
