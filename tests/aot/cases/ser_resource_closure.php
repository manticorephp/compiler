<?php

// php serializes ANY resource as the integer 0, and refuses a Closure with a
// catchable Exception. A closure record is `[fn_ptr, capture…]` with no class
// descriptor, so the generated object walker must throw rather than deref it.

$f = fopen('/dev/null', 'r');
echo serialize($f), "\n";
echo serialize([$f, 1, 'k' => $f]), "\n";

$o = new stdClass();
$o->r = $f;
echo serialize($o), "\n";
fclose($f);

$c = function () { return 1; };
try {
    serialize($c);
    echo "NOT REACHED\n";
} catch (\Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$n = 5;
$u = function () use ($n) { return $n; };
try {
    serialize([$u]);
    echo "NOT REACHED\n";
} catch (\Exception $e) {
    echo $e->getMessage(), "\n";
}

echo "after\n";
