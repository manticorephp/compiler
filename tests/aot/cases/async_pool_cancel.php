<?php
// Async\Pool (superset, no Zend oracle): cancelled and timed-out waiters never leak a slot or lose a wake-up. Hand-written expectation.
use function Async\async;
use function Async\spawn;
use function Async\awaitAllSettled;
use function Async\delay;
use function Async\timeout;
use Async\Pool;
use Async\TimeoutException;
use Async\CancelledException;

final class Conn
{
    public static int $made = 0;
    public int $id;
    public function __construct() { $this->id = ++self::$made; }
}

async(function () {
    // a waiter cancelled while parked does not take the slot: the next waiter gets it
    $pool = new Pool(fn() => new Conn(), 1);
    $a = $pool->acquire();
    $w1 = spawn(function () use ($pool) { $c = $pool->acquire(); $pool->release($c); return 'w1'; });
    $w2 = spawn(function () use ($pool) { $c = $pool->acquire(); $pool->release($c); return 'w2'; });
    delay(0.01);
    $w1->cancel();
    delay(0.01);
    $pool->release($a);
    echo $w2->await(), " waiting=", $pool->stats()['waiting'], " idle=", $pool->stats()['idle'], " busy=", $pool->stats()['busy'], "\n";
    try { $w1->await(); echo "w1: MISSING\n"; } catch (CancelledException $e) { echo "w1 cancelled\n"; }

    // cancelled in the same tick as the hand-off: the grant is passed on, not lost
    $pool = new Pool(fn() => new Conn(), 1);
    $a = $pool->acquire();
    $w1 = spawn(function () use ($pool) { $c = $pool->acquire(); $pool->release($c); return 'w1'; });
    $w2 = spawn(function () use ($pool) { $c = $pool->acquire(); $pool->release($c); return 'w2'; });
    delay(0.01);
    $pool->release($a);   // hands to w1 (woken, not yet running)
    $w1->cancel();        // ... which is then cancelled before it runs
    echo $w2->await(), " busy=", $pool->stats()['busy'], " idle=", $pool->stats()['idle'], "\n";
    awaitAllSettled($w1);

    // timeout of one waiter does not disturb the queue behind it
    $pool = new Pool(fn() => new Conn(), 1);
    $a = $pool->acquire();
    $short = spawn(function () use ($pool) { try { $pool->acquire(0.03); return 'got'; } catch (TimeoutException $e) { return 'timeout'; } });
    $long = spawn(function () use ($pool) { $c = $pool->acquire(2.0); $pool->release($c); return 'long'; });
    echo $short->await(), " waiting=", $pool->stats()['waiting'], "\n";
    $pool->release($a);
    echo $long->await(), " busy=", $pool->stats()['busy'], "\n";

    // with(): a cancelled body discards the connection
    Conn::$made = 0;
    $pool = new Pool(fn() => new Conn(), 1);
    $t = spawn(function () use ($pool) { $pool->with(function () { delay(5.0); }); });
    delay(0.01);
    $t->cancel();
    awaitAllSettled($t);
    $s = $pool->stats();
    echo "cancelled body: idle=", $s['idle'], " busy=", $s['busy'], " discarded=", $s['discarded'], "\n";
    $c = $pool->acquire();
    echo "fresh after discard id=", $c->id, "\n";
    $pool->release($c);

    // timeout() around acquire
    $pool = new Pool(fn() => new Conn(), 1);
    $a = $pool->acquire();
    try { timeout(0.03, fn() => $pool->acquire()); echo "scope: MISSING\n"; } catch (TimeoutException $e) { echo "scope timeout waiting=", $pool->stats()['waiting'], "\n"; }
    $pool->release($a);

    // conservation under churn: 30 tasks, some with short timeouts, some cancelled
    $pool = new Pool(fn() => new Conn(), 3);
    $ts = [];
    for ($i = 0; $i < 30; $i++) {
        $ts[] = spawn(function () use ($pool, $i) {
            try {
                $c = $pool->acquire($i % 3 === 0 ? 0.02 : null);
            } catch (TimeoutException $e) {
                return 't';
            }
            delay(0.01);
            $pool->release($c);
            return 'ok';
        });
    }
    delay(0.015);
    foreach ($ts as $i => $t) { if ($i % 7 === 1) { $t->cancel(); } }
    awaitAllSettled(...$ts);
    $s = $pool->stats();
    echo "churn: busy=", $s['busy'], " waiting=", $s['waiting'], " idle<=3=", $s['idle'] <= 3 ? 'yes' : 'no', " created<=3=", $s['created'] <= 3 ? 'yes' : 'no', "\n";
    echo "pooled\n";
});
