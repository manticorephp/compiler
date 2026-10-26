<?php
class C { public int $x = 1; }
$c = new C;
try {
    var_dump($c[0]);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
