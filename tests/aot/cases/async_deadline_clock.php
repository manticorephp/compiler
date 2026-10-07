<?php
// Deadlines run on the monotonic clock; the public deadline() still reports a
// unix time. And an awaitDeadline() that expires stops waiting for real: the
// task it gave up on must not wake it later, wherever it is parked by then.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\timeout;
use Async\Context;
use Async\Scheduler;

async(function () {
    timeout(2.0, function () {
        $d = Context::deadline();
        $skew = $d - \microtime(true) - 2.0;
        echo "deadline is unix time: ", ($skew < 0.0 && $skew > -0.5) ? "yes" : "no", "\n";
        $r = Context::remaining();
        echo "remaining ~2s: ", ($r > 1.5 && $r <= 2.0) ? "yes" : "no", "\n";
    });
});

async(function () {
    $ch = Async\channel();
    $slow = spawn(function () { delay(0.1); return 1; });
    $sched = Scheduler::instance();
    $settled = $sched->awaitDeadline($slow, \__mc_monotonic_f() + 0.02);
    echo "expired: ", $settled ? "no" : "yes", "\n";
    spawn(function () use ($ch) {
        delay(0.3);
        echo "recv: ", $ch->recv(), "\n";
    });
    $ch->send(42);        // $slow settles at 0.1 s: it must not end this send
    echo "sent\n";
});
