<?php
// stream_select() leaks memory on every call even when the caller does not reassign the read set.
[$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fwrite($a, 'x');
$r = [$b]; $w = null; $e = null;
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 1000000; $i++) { stream_select($r, $w, $e, 0); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
echo $mb < 4 ? "flat\n" : "LEAK\n";
