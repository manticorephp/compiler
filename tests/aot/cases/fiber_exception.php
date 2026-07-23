<?php
$f = new Fiber(function() {
    echo "before throw\n";
    throw new \RuntimeException("boom");
});
try {
    $f->start();
    echo "unreachable\n";
} catch (\Throwable $e) {
    echo "main caught: " . $e->getMessage() . "\n";
}
echo "after\n";
