<?php
// Async\Pool (superset, no Zend oracle): bound, FIFO, timeout, discard, lifetime, close. Expectation is hand-written.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
use Async\Pool;
use Async\TimeoutException;

final class Conn
{
    public static int $made = 0;
    public static int $live = 0;
    public static int $peak = 0;
    public int $id;
    public int $uses = 0;
    public function __construct() { $this->id = ++self::$made; }
}

final class Stat { public int $inUse = 0; public int $peak = 0; public string $log = ''; }

async(function () {
    // max respected under 50 tasks
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 5);
    $st = new Stat();
    $tasks = [];
    for ($i = 0; $i < 50; $i++) {
        $tasks[] = spawn(function () use ($pool, $st) {
            $pool->with(function (Conn $c) use ($st) {
                $st->inUse++;
                if ($st->inUse > $st->peak) { $st->peak = $st->inUse; }
                $c->uses++;
                delay(0.01);
                $st->inUse--;
            });
        });
    }
    awaitAll(...$tasks);
    $s = $pool->stats();
    echo "peak=", $st->peak, " created=", $s['created'], " idle=", $s['idle'], " busy=", $s['busy'], " waiting=", $s['waiting'], "\n";

    // FIFO: waiters are served in arrival order, and a newcomer does not overtake
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1);
    $order = new Stat();
    $hold = $pool->acquire();
    $ts = [];
    for ($i = 1; $i <= 4; $i++) {
        $ts[] = spawn(function () use ($pool, $order, $i) {
            $c = $pool->acquire();
            $order->log .= $i;
            delay(0.005);
            $pool->release($c);
        });
        delay(0.002);
    }
    echo "waiting=", $pool->stats()['waiting'], "\n";
    $pool->release($hold);
    $late = spawn(function () use ($pool, $order) {
        $c = $pool->acquire();
        $order->log .= 'L';
        $pool->release($c);
    });
    awaitAll(...$ts);
    $late->await();
    echo "fifo=", $order->log, "\n";

    // timeout while waiting; the slot count is intact afterwards
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1);
    $c = $pool->acquire();
    $t0 = microtime(true);
    try {
        $pool->acquire(0.05);
        echo "timeout: MISSING\n";
    } catch (TimeoutException $e) {
        $ms = (microtime(true) - $t0) * 1000;
        echo "timeout: ok prompt=", ($ms >= 40 && $ms < 1000) ? "yes" : "no", " waiting=", $pool->stats()['waiting'], "\n";
    }
    $pool->release($c);
    $c2 = $pool->acquire(0.05);
    echo "reacquired same=", $c2 === $c ? "yes" : "no", "\n";
    $pool->release($c2);

    // an exception in with() releases the connection (it stays usable)
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1);
    try { $pool->with(function () { throw new RuntimeException('boom'); }); } catch (RuntimeException $e) { echo "with threw ", $e->getMessage(), "\n"; }
    $s = $pool->stats();
    echo "after throw idle=", $s['idle'], " busy=", $s['busy'], " discarded=", $s['discarded'], "\n";

    // discard creates capacity for a parked waiter
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1);
    $a = $pool->acquire();
    $w = spawn(function () use ($pool) { $c = $pool->acquire(); $id = $c->id; $pool->release($c); return $id; });
    delay(0.01);
    $pool->discard($a);
    echo "waiter got fresh id=", $w->await(), " created=", $pool->stats()['created'], " discarded=", $pool->stats()['discarded'], "\n";

    // double release / foreign release
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 2);
    $other = new Pool(fn() => new Conn(), 2);
    $c = $pool->acquire();
    $pool->release($c);
    try { $pool->release($c); echo "double: MISSING\n"; } catch (LogicException $e) { echo "double: LogicException\n"; }
    $f = $other->acquire();
    try { $pool->release($f); echo "foreign: MISSING\n"; } catch (LogicException $e) { echo "foreign: LogicException\n"; }
    try { $pool->release(42); echo "scalar: MISSING\n"; } catch (LogicException $e) { echo "scalar: LogicException\n"; }

    // reset runs on release; a failing reset discards
    $resets = new Stat();
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 2, function (Conn $c) use ($resets) {
        $resets->peak++;
        if ($c->id === 1) { throw new RuntimeException('reset failed'); }
    });
    $c = $pool->acquire();
    $pool->release($c);
    $s = $pool->stats();
    echo "reset calls=", $resets->peak, " idle=", $s['idle'], " discarded=", $s['discarded'], "\n";
    $c = $pool->acquire();
    echo "next id=", $c->id, "\n";
    $pool->release($c);

    // maxLifetime recreates; idleTimeout recreates
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1, null, null, 0.2);
    $c = $pool->acquire(); $id1 = $c->id; $pool->release($c);
    $c = $pool->acquire(); $same = $c->id === $id1; $pool->release($c);
    delay(0.5);
    $c = $pool->acquire(); $new = $c->id !== $id1; $pool->release($c);
    echo "lifetime: reused=", $same ? "yes" : "no", " recreated=", $new ? "yes" : "no", "\n";
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1, null, null, 0.0, 0.2);
    $c = $pool->acquire(); $id1 = $c->id; $pool->release($c);
    delay(0.5);
    $c = $pool->acquire(); echo "idle: recreated=", $c->id !== $id1 ? "yes" : "no", " idle=", $pool->stats()['idle'], "\n";
    $pool->release($c);

    // ping at acquire: false discards, only idle objects are pinged
    $bad = new Stat();
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1, null, function (Conn $c) use ($bad) { $bad->peak++; return $c->id !== 1; });
    $c = $pool->acquire(); echo "fresh pinged=", $bad->peak, "\n"; $pool->release($c);
    $c = $pool->acquire(); echo "ping false: id=", $c->id, " pings=", $bad->peak, "\n"; $pool->release($c);

    // close(): waiters get an exception, idle objects are closed, later acquires refuse
    $closed = new Stat();
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1, null, null, 0.0, 0.0, function (Conn $c) use ($closed) { $closed->log .= 'c'; });
    $a = $pool->acquire();
    $w1 = spawn(function () use ($pool) { try { $pool->acquire(); return 'got'; } catch (RuntimeException $e) { return $e->getMessage(); } });
    $w2 = spawn(function () use ($pool) { try { $pool->acquire(); return 'got'; } catch (RuntimeException $e) { return $e->getMessage(); } });
    delay(0.01);
    $pool->close();
    echo $w1->await(), " | ", $w2->await(), "\n";
    $pool->release($a);
    echo "closed log=", $closed->log, "\n";
    try { $pool->acquire(); } catch (RuntimeException $e) { echo "after close: ", $e->getMessage(), "\n"; }

    // max < 1 is refused, not clamped
    try { new Pool(fn() => new Conn(), 0); echo "max: MISSING\n"; } catch (ValueError $e) { echo "max: ValueError\n"; }

    // with(): a PDOException of SQLSTATE class 08 discards, any other returns the connection
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1);
    foreach (['08006', '23505'] as $state) {
        try {
            $pool->with(function () use ($state) { $e = new PDOException('x'); $e->sqlstate = $state; throw $e; });
        } catch (PDOException $e) {}
        $s = $pool->stats();
        echo "sqlstate ", $state, ": idle=", $s['idle'], " discarded=", $s['discarded'], "\n";
    }

    // a reset that throws inside with()'s finally discards; the body's result still returns
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1, function () { throw new RuntimeException('reset'); });
    echo "with result=", $pool->with(fn(Conn $c) => 'r' . $c->id), "\n";
    $s = $pool->stats();
    echo "reset threw: idle=", $s['idle'], " busy=", $s['busy'], " discarded=", $s['discarded'], "\n";

    // close() between a hand-off and the waiter resuming: the grant is dropped, the waiter gets the exception
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1, null, null, 0.0, 0.0, function (Conn $c) use ($closed) { $closed->log .= 'd'; });
    $closed->log = '';
    $a = $pool->acquire();
    $w = spawn(function () use ($pool) { try { $pool->acquire(); return 'got'; } catch (RuntimeException $e) { return 'closed'; } });
    delay(0.01);
    $pool->release($a);   // hands to $w (woken, not yet running)
    $pool->close();
    echo $w->await(), " log=", $closed->log, " busy=", $pool->stats()['busy'], " idle=", $pool->stats()['idle'], "\n";
    echo "pooled\n";
});
