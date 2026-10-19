<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_MY_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
async(function () {
    $t0 = microtime(true);
    $tasks = [];
    for ($i = 0; $i < 20; $i++) {
        $tasks[] = spawn(function () use ($i) {
            $db = pdo();
            $st = $db->query('select sleep(0.2), ' . $i . ' as i');
            $r = $st->fetch(PDO::FETCH_NUM);
            return (int) $r[1];
        });
    }
    $res = awaitAll(...$tasks);
    $ms = (microtime(true) - $t0) * 1000;
    echo array_sum($res), "\n";
    // serial would take 20 x 0.2 s = 4 s; 2.5 s leaves slack for a loaded CI box
    echo $ms < 2500 ? 'parallel' : 'serial', "\n";
});
