<?php
// Inserting keys while a foreach iterates a Manticore\Ds\Map never throws, so the loop runs away
use Manticore\Ds\Map;
$m = new Map();
$m->set('a', 1);
$n = 0;
try {
    foreach ($m as $k => $v) {
        $n++;
        if ($n > 1000) { echo "runaway\n"; break; }
        $m->set('k' . $n, $n);
    }
    echo "finished\n";
} catch (\RuntimeException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
