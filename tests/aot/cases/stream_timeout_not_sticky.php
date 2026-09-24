<?php
// MANTICORE-ONLY (php has no Async\ scheduler). Pin for round-2 review item
// B: `timed_out` is per OPERATION — php resets it before every read
// (php_stream_read), not once and never again. fread()/fgets() now reset
// `$stream->timedOut = false` at the top of every call; before that fix, one
// real timeout left `timed_out` stuck at true forever, so a LATER read that
// hit genuine EOF (not a timeout at all) still reported
// stream_get_meta_data()['timed_out'] === true.

use function Async\async;
use function Async\spawn;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);

async(function () use ($l, $port) {
    spawn(function () use ($l) {
        $c = stream_socket_accept($l, 5.0);
        stream_set_blocking($c, false);
        // Silent long enough to force exactly ONE real read timeout on the
        // client (100ms), then close — the client's SECOND read is a genuine
        // EOF, not another timeout.
        \Async\delay(0.3);
        fclose($c);
    });

    $errno = 0;
    $errstr = '';
    $c = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5.0);
    stream_set_blocking($c, false);
    stream_set_timeout($c, 0, 100000);   // 100ms — well under the server's 0.3s silence

    $r1 = fread($c, 100);
    $m1 = stream_get_meta_data($c);
    echo 'first: len=', strlen($r1), ' timed_out=', $m1['timed_out'] ? 'yes' : 'no', "\n";

    // Past the server's close (0.3s total, 0.1s already spent above).
    \Async\delay(0.25);
    $r2 = fread($c, 100);
    $m2 = stream_get_meta_data($c);
    echo 'second: len=', strlen($r2), ' timed_out=', $m2['timed_out'] ? 'yes' : 'no', "\n";

    fclose($c);
    fclose($l);
});
