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
    $t = $rd(1); $l = unpack('N', $rd(4))[1]; $rd($l - 4);   // the Q
    if ($mode === 'truncated_datarow') {
        $rowdesc = pack('n', 1) . "a\0" . pack('NnNnNn', 0, 0, 25, -1 & 0xffff, 0xffffffff, 0);
        fwrite($c, $msg('T', $rowdesc) . $msg('D', pack('n', 2) . pack('N', 1) . 'x') . $z);
    } elseif ($mode === 'truncated_rowdesc') {
        fwrite($c, $msg('T', pack('n', 2) . "a\0" . 'short') . $z);
    } else {
        fwrite($c, $msg('G', "\0" . pack('n', 0)));
        $t = $rd(1);                                         // expect CopyFail 'f'
        $l = unpack('N', $rd(4))[1]; $rd($l - 4);
        fwrite($c, $msg('E', "SERROR\0C57014\0Mcopy failed\0\0") . $z);
    }
}
$end = microtime(true) + 6;
stream_set_timeout($c, 1);
while (microtime(true) < $end) { $x = fread($c, 1024); if ($x === '' && feof($c)) { break; } }
