<?php
use function Async\{async, spawn, delay, awaitAny};
try {
    async(function () {
        $fast = spawn(function () { delay(0.01); return 'fast'; });
        $slow = spawn(function () { delay(0.05); throw new RuntimeException('loser failed'); });
        echo awaitAny($fast, $slow), "\n";
        delay(0.1);
        echo "end\n";
    });
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
