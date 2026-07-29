<?php

// Many tasks parked on ONE channel, then cancelled together.
//
// removeWaiter used to rebuild both queues on every removal, so tearing down N
// waiters was O(N^2) — and it allocated an N-sized array N times, which showed up
// as a per-task RSS that GREW with the task count (71 KiB each at 10k, 111 KiB at
// 20k). A cancelled scope of 40k channel waiters did not finish in ten minutes.
//
// The queues are cursor queues with tombstones now, so this is linear. The
// assertion is deliberately behavioural rather than a timing or memory number:
// the scope closes, every task settles, and the suite's per-case deadline is what
// catches a return to quadratic — before the fix this case could not finish.
//
// ⚠ Nothing printed before the first Async\ call — difftest treats a file as
// manticore-only when php produces NO stdout.

\Async\async(function () {
    $n = 8000;
    $gate = new \Async\Channel(0);
    $ticks = 0;

    \Async\group(function (\Async\TaskGroup $g) use ($n, $gate, &$ticks) {
        for ($i = 0; $i < $n; $i++) {
            $g->spawn(function () use ($gate) { $gate->recv(); });
        }
        // A sibling that must keep running while all of that is parked.
        $g->spawn(function () use (&$ticks) {
            for ($i = 0; $i < 5; $i++) {
                \Async\delay(0.002);
                $ticks++;
            }
        });
        // Wait for the sibling to finish rather than guessing a duration: spawning
        // 8000 tasks takes longer than any fixed sleep worth writing here.
        while ($ticks < 5) { \Async\delay(0.002); }
        $g->cancel();
    });

    echo "sibling ran: ", $ticks === 5 ? 'yes' : 'no', "\n";
    echo "live back to baseline: ", \Async\stats()['live'] <= 1 ? 'yes' : 'no', "\n";

    // The channel still works afterwards: a tombstoned queue must not strand a
    // later rendezvous.
    $ch = new \Async\Channel(0);
    $p = \Async\spawn(function () use ($ch) { $ch->send('after'); });
    echo "still delivers: ", $ch->recv(), "\n";
    $p->await();
});
