<?php
// Collecting OUTCOMES instead of failing fast — "fetch a hundred and tell me
// which ones broke". Neither entry point throws, and neither cancels anything.

use function Async\async;
use function Async\awaitAllSettled;
use function Async\delay;
use function Async\mapSettled;
use function Async\spawn;

final class Gauge { public int $cur = 0; public int $max = 0; }

async(function () {
    $ok = spawn(function () { delay(0.01); return "one"; });
    $bad = spawn(function () { delay(0.005); throw new \RuntimeException("nope"); });
    $ok2 = spawn(fn() => "three");

    $res = awaitAllSettled($ok, $bad, $ok2);
    foreach ($res as $i => $r) {
        echo "settled[", $i, "]: ", $r->ok ? "ok " . $r->value : "err " . $r->error->getMessage(), "\n";
    }

    // The successful siblings are NOT cancelled by the failure — that is the
    // whole difference from awaitAll().
    echo "survivors: ", $res[0]->ok && $res[2]->ok ? "both" : "lost", "\n";

    // mapSettled: bounded concurrency, one outcome per input position.
    $g = new Gauge();
    $out = mapSettled([1, 2, 3, 4, 5], function (int $n) use ($g) {
        $g->cur = $g->cur + 1;
        if ($g->cur > $g->max) { $g->max = $g->cur; }
        delay(0.01);
        $g->cur = $g->cur - 1;
        if ($n % 2 === 0) { throw new \RuntimeException("even " . $n); }
        return $n * 10;
    }, 2);
    $line = "";
    foreach ($out as $r) {
        $line = $line . ($r->ok ? (string)$r->value : "[" . $r->error->getMessage() . "]") . " ";
    }
    echo "map: ", $line, "\n";
    echo "map max-inflight: ", $g->max, "\n";
    echo "empty: ", \count(awaitAllSettled()), "\n";
    echo "done\n";
});
