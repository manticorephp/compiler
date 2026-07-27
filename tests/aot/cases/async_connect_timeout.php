<?php

// A connect that never completes must honour the caller's timeout under a
// scheduler. Both the non-blocking connect(2) and the TLS handshake used to park
// the fiber FOREVER (`__mc_await_connect` and `__mc_tls_drive_connect` had no
// deadline), so fsockopen's 5th argument was silently ignored and one unreachable
// host wedged the whole program.
//
// 192.0.2.1 is RFC 5737 TEST-NET-1: never routed, never answers. With no network
// at all it fails fast instead of timing out — either way the answers below are the
// same, which keeps this case offline-safe. What it really pins down is that the
// call RETURNS: if the deadline is lost the suite hangs here.
//
// No elapsed times are printed on purpose: they are not reproducible.

use function Async\async;
use function Async\spawn;

$plain = async(function (): string {
    $c = spawn(function (): string {
        $e = 0; $m = '';
        $s = @fsockopen('192.0.2.1', 80, $e, $m, 0.3);
        return $s === false ? 'false' : 'conn';
    });
    // The loop must keep running while that connect waits.
    $tick = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 5; $i = $i + 1) { \Async\delay(0.02); $n = $n + 1; }
        return $n;
    });
    return $c->await() . ' ticks=' . (string)$tick->await();
});
echo 'plain: ', $plain, "\n";

$tls = async(function (): string {
    $c = spawn(function (): string {
        $e = 0; $m = '';
        $s = @stream_socket_client('tls://192.0.2.1:443', $e, $m, 0.3);
        return $s === false ? 'false' : 'conn';
    });
    return $c->await();
});
echo 'tls: ', $tls, "\n";

// Sync mode is unchanged: the same call outside a scheduler still answers false.
$e2 = 0; $m2 = '';
var_dump(@fsockopen('192.0.2.1', 80, $e2, $m2, 0.3) === false);
echo "done\n";
