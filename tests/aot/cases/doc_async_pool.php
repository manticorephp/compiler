<?php

use Async\Pool;
use Async\TimeoutException;
use function Async\async;
use function Async\awaitAll;
use function Async\delay;
use function Async\spawn;

final class Connection
{
    public static int $made = 0;
    public int $id;

    public function __construct()
    {
        $this->id = ++self::$made;
    }
}

async(function () {
    // At most two connections ever exist; waiters are served in arrival order.
    $pool = new Pool(fn() => new Connection(), 2);

    $jobs = [];
    for ($i = 1; $i <= 4; $i++) {
        $jobs[] = spawn(fn() => $pool->with(function (Connection $c) {
            delay(0.01);
            return $c->id;
        }));
    }
    echo implode(",", awaitAll(...$jobs)), "\n";

    $stats = $pool->stats();
    echo "created {$stats['created']}, idle {$stats['idle']}, busy {$stats['busy']}\n";

    // Bound the wait for a free connection with a timeout.
    $a = $pool->acquire();
    $b = $pool->acquire();
    try {
        $pool->acquire(0.01);
    } catch (TimeoutException $e) {
        echo "no connection free\n";
    }
    $pool->release($a);
    $pool->discard($b);
    echo "discarded: {$pool->stats()['discarded']}\n";

    $pool->close();
});
