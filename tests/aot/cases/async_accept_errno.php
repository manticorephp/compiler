<?php

// accept(2)'s failure classifier and its overload backoff.
//
// The accept loop used to re-park on ANY failure. Out of descriptors that is a hot
// SPIN, not a wait: accept(2) fails while the pending connection stays queued, so a
// level-triggered listener stays readable and the park returns at once, forever.
// It never trips the watchdog (it suspends every iteration) — it only shows as an
// exploding `wakes` counter while every sibling task starves.
//
// Asserted through the __mc_sock_const() selector, never a raw errno, so this reads
// the same on Darwin (ECONNABORTED 53, ENOBUFS 55, EPROTO 100) and Linux (103, 105,
// 71). EMFILE/ENFILE/ENOMEM/EINTR are the same number on both.
//
// Exhausting descriptors for real is not reachable from a test — `ulimit -n` here is
// six figures and there is no setrlimit(2) binding. The manual recipe:
//   bash -c 'ulimit -n 64; ./server' + a connection flood, and watch
//   Async\stats()['wakes'] stay flat instead of climbing without bound.
//
// ⚠ Nothing printed before the first __mc_* call: difftest treats a file as
// manticore-only when php produces NO stdout, and php has no __mc_accept_class.

$names = ['nobody-set', 'EWOULDBLOCK', 'EAGAIN', 'EINTR', 'ECONNABORTED', 'EPROTO',
          'EMFILE', 'ENFILE', 'ENOBUFS', 'ENOMEM', 'bogus'];
$codes = [0, __mc_sock_const(10), __mc_sock_const(11), 4, __mc_sock_const(16),
          __mc_sock_const(19), __mc_sock_const(14), __mc_sock_const(15),
          __mc_sock_const(17), __mc_sock_const(18), 9999];
$class = ['park', 'retry', 'backoff', 'fatal'];

foreach ($codes as $i => $c) {
    echo $names[$i], ' => ', $class[__mc_accept_class($c)], "\n";
}

// The classifier is SEPARATE from __mc_sock_wouldblock, and deliberately of the
// opposite polarity: there an unknown errno parks (a read must not truncate live
// data), here it is fatal (the alternative is an infinite loop). Widening the
// would-block set would make every socket_read/socket_send spin on EMFILE too.
echo "emfile-is-overload: ", (__mc_accept_class(__mc_sock_const(14)) === 2 ? 'yes' : 'no'), "\n";
echo "unknown-is-fatal: ", (__mc_accept_class(9999) === 3 ? 'yes' : 'no'), "\n";

// 1 ms, doubling, capped at 50 ms.
$steps = [];
$b = 0.0;
for ($i = 0; $i < 8; $i = $i + 1) {
    $b = __mc_accept_backoff($b);
    $steps[] = (string)(int)round($b * 1000.0);
}
echo "backoff-ms: ", implode(',', $steps), "\n";
echo "done\n";
