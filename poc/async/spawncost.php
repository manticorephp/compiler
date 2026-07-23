<?php
use function Async\run;
use function Async\spawn;
run(function () {
    $N = 30000;
    $t0 = microtime(true);
    for ($i = 0; $i < $N; $i++) {
        $t = spawn(function () { return 0; });
        $t->await();     // run + settle + prune → fiber reclaimed (munmap)
    }
    $dt = microtime(true) - $t0;
    printf("spawn+run+destroy: %d/s  (%.2f us each)\n", (int)($N / $dt), $dt * 1e6 / $N);
});
