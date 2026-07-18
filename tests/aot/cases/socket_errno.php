<?php
// errno is host-specific (ECONNREFUSED = 61 Darwin / 111 Linux), so assert only
// portable facts: the connect is refused, errno is set (non-zero), and the message
// is strerror's "Connection refused". Values match php byte-for-byte per host —
// tools/difftest.sh verifies that live; this fixed-expected case stays portable.
$e = 0; $m = '';
$fp = @fsockopen('127.0.0.1', 1, $e, $m, 2);
echo ($fp === false ? 'refused' : 'ok')
   . ' ' . ($e !== 0 ? 'errno-set' : 'no-errno')
   . ' ' . (strpos(strtolower($m), 'refus') !== false ? 'msg-ok' : 'msg-bad') . "\n";
$e2 = 0; $m2 = '';
$c = @stream_socket_client('tcp://127.0.0.1:1', $e2, $m2, 2);
echo ($c === false ? 'refused' : 'ok') . ' ' . ($e2 === $e ? 'same-errno' : 'diff') . "\n";
