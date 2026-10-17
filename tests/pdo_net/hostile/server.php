<?php
// Fake hostile Postgres server (Zend php). usage: server.php <mode> <portfile>
// Serves ONE connection, then waits until the client hangs up (or 6 s).
[, $mode, $portFile] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
file_put_contents($portFile . '.tmp', (string) parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT));
rename($portFile . '.tmp', $portFile);
$c = stream_socket_accept($srv, 10);
$rd = function (int $n) use ($c): string { $b = ''; while (strlen($b) < $n) { $x = fread($c, $n - strlen($b)); if ($x === '' || $x === false) { return $b; } $b .= $x; } return $b; };
$msg = fn (string $t, string $body): string => $t . pack('N', strlen($body) + 4) . $body;
$len = unpack('N', $rd(4))[1];
$rd($len - 4);                                  // startup packet
$z = $msg('Z', 'I');
// one client request: a simple Q, or an extended batch up to its Sync
$batch = function () use ($rd): void { while (true) { $t = $rd(1); if ($t === '') { return; } $l = unpack('N', $rd(4))[1]; $rd($l - 4); if ($t === 'Q' || $t === 'S') { return; } } };
$col = pack('n', 1) . "a\0" . pack('NnNnNn', 0, 0, 25, -1 & 0xffff, 0xffffffff, 0);
if ($mode === 'huge') { fwrite($c, 'R' . pack('N', 0x7fffffff)); }
elseif ($mode === 'short') { fwrite($c, 'R' . pack('N', 2)); }
elseif ($mode === 'authok_after_sasl') {
    fwrite($c, $msg('R', pack('N', 10) . "SCRAM-SHA-256\0\0"));
    $t = $rd(1); $l = unpack('N', $rd(4))[1]; $rd($l - 4);   // SASLInitialResponse
    fwrite($c, $msg('R', pack('N', 0)) . $z);
} elseif ($mode === 'sasl_final_unsolicited') {
    fwrite($c, $msg('R', pack('N', 12) . 'v=AAAA') . $msg('R', pack('N', 0)) . $z);
} elseif ($mode === 'truncated_datarow' || $mode === 'truncated_rowdesc' || $mode === 'copy_in') {
    fwrite($c, $msg('R', pack('N', 0)) . $z);
    $batch();
    if ($mode === 'truncated_datarow') {
        fwrite($c, $msg('T', $col) . $msg('D', pack('n', 2) . pack('N', 1) . 'x') . $z);
    } elseif ($mode === 'truncated_rowdesc') {
        fwrite($c, $msg('T', pack('n', 2) . "a\0" . 'short') . $z);
    } else {
        fwrite($c, $msg('G', "\0" . pack('n', 0)));
        $t = $rd(1);                                         // expect CopyFail 'f'
        $l = unpack('N', $rd(4))[1]; $rd($l - 4);
        fwrite($c, $msg('E', "SERROR\0C57014\0Mcopy failed\0\0") . $z);
    }
} elseif ($mode === 'paramdesc_truncated' || $mode === 'paramdesc_mismatch' || $mode === 'datarow_overrun') {
    fwrite($c, $msg('R', pack('N', 0)) . $z);
    $batch();                                               // Parse Describe Bind Execute Sync
    if ($mode === 'paramdesc_truncated') {
        fwrite($c, $msg('1', '') . $msg('t', pack('nN', 3, 25)) . $z);          // claims 3 parameters, carries 1
    } elseif ($mode === 'datarow_overrun') {
        fwrite($c, $msg('1', '') . $msg('t', pack('n', 0)) . $msg('T', $col) . $msg('2', '')
            . $msg('D', pack('n', 1) . pack('N', 1000) . 'abc') . $z);                // field length overruns the body
    } else {
        // a server that wants 2 parameters for a 1-placeholder statement: it answers the Bind with its own error
        fwrite($c, $msg('1', '') . $msg('t', pack('nNN', 2, 25, 25)) . $msg('T', $col)
            . $msg('E', "SERROR\0C08P01\0Mbind message supplies 1 parameters, but prepared statement \"pdo_stmt_00000001\" requires 2\0\0") . $z);
        $batch();                                           // the client's next query must still work
        fwrite($c, $msg('1', '') . $msg('t', pack('n', 0)) . $msg('T', $col) . $msg('2', '')
            . $msg('D', pack('n', 1) . pack('N', 2) . 'ok') . $msg('C', "SELECT 1\0") . $z);
    }
}
$end = microtime(true) + 6;
stream_set_timeout($c, 1);
while (microtime(true) < $end) { $x = fread($c, 1024); if ($x === '' && feof($c)) { break; } }
