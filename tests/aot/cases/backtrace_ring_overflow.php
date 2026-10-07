<?php
// Frames past the 4096-deep ring are counted but not stored: a 5000-deep throw
// must not read or write past the ring, and its trace keeps the callee names.
function down(int $n) {
    if ($n === 0) { throw new Exception("bottom"); }
    return down($n - 1) + 1;
}
class K {
    function m(int $n) {
        if ($n === 0) { echo count(debug_backtrace()) > 4000 ? "bt\n" : "none\n"; return; }
        $this->m($n - 1);
    }
}
try {
    down(5000);
} catch (Exception $e) {
    $t = $e->getTrace();
    echo count($t) > 4000 ? "deep" : "shallow", "\n";
    echo $t[0]['function'], ' ', $t[1]['function'], "\n";
}
(new K)->m(5000);
