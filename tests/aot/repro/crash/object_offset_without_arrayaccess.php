<?php
// Reading $obj[0] on an object whose class lacks ArrayAccess crashes (SIGBUS) instead of throwing Error
// issue: #479
class C { public int $x = 1; }
$c = new C;
try {
    var_dump($c[0]);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
