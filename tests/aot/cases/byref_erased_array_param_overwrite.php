<?php
// socket_select overwriting its untyped by-ref array parameter leaks the caller's previous array on every call.
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $p);
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 100000; $i++) {
    $r = array_fill(0, 64, $p[0]);
    $w = null;
    $e = null;
    socket_select($r, $w, $e, 0);
}
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
