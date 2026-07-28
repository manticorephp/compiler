<?php

// EMFILE end to end: the accept backoff, driven by a REAL exhausted descriptor
// table rather than the classifier in isolation (async_accept_errno).
//
// Out of descriptors, accept(2) fails while the pending connection stays QUEUED,
// so a level-triggered listener stays readable and a naive loop spins at 100% CPU
// without ever suspending long enough to look stuck. What proves the backoff works
// is not a counter — it is that a SIBLING task keeps making progress while the
// acceptor cannot accept.
//
// posix_setrlimit is what makes this reachable at all: `ulimit -n` on a dev box is
// six or seven figures, and until this epic there was no setrlimit(2) binding.
//
// ⚠ Nothing printed before the first Async\ call — difftest treats a file as
// manticore-only when php produces NO stdout.

\Async\async(function () {
    $errno = 0;
    $errstr = '';
    $cerr = 0;
    $cstr = '';
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $addr = \stream_socket_get_name($server, false);

    // Leave room for the listener, the client we are about to open, and the
    // runtime's own descriptors; then eat the rest.
    $before = \posix_getrlimit(\POSIX_RLIMIT_NOFILE);
    $hard = $before[1] === 'unlimited' ? \PHP_INT_MAX : $before[1];
    $client = \stream_socket_client($addr, $cerr, $cstr, 2.0);
    \posix_setrlimit(\POSIX_RLIMIT_NOFILE, 24, $hard);

    // Burn every remaining descriptor, so the connection above is pending against
    // an accept(2) that can only answer EMFILE.
    $hogs = [];
    while (\count($hogs) < 64) {
        $h = @\fopen('/dev/null', 'rb');
        if ($h === false) { break; }
        $hogs[] = $h;
    }
    $exhausted = \count($hogs) < 64;

    $ticks = 0;
    \Async\group(function (\Async\TaskGroup $g) use ($server, &$ticks, &$hogs, $hard) {
        // The acceptor: parked in the backoff for as long as the table is full.
        $g->spawn(function () use ($server) {
            $conn = @\stream_socket_accept($server, 3.0);
            if ($conn !== false) { \fclose($conn); }
        });
        // The proof: a sibling that must keep running throughout. A spinning
        // acceptor starves this — it never suspends long enough to let it tick.
        $g->spawn(function () use (&$ticks, &$hogs, $hard) {
            for ($i = 0; $i < 10; $i++) {
                \Async\delay(0.01);
                $ticks++;
            }
            // Give the descriptors back; the queued connection is accepted now.
            foreach ($hogs as $h) { \fclose($h); }
            $hogs = [];
            \posix_setrlimit(\POSIX_RLIMIT_NOFILE, $hard, $hard);
        });
    });

    echo "exhausted: ", $exhausted ? 'yes' : 'no', "\n";
    echo "sibling ran: ", $ticks === 10 ? 'yes' : 'no', "\n";
    echo "still serving: ", \is_resource($server) ? 'yes' : 'no', "\n";
    \fclose($server);
    if ($client !== false) { \fclose($client); }
});
