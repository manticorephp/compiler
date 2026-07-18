<?php
$e = 0; $m = ''; $e2 = 0; $m2 = '';
$port = 0;
$srv = false;
for ($p = 51000; $p < 51090; $p++) {
    $s = @stream_socket_server('udp://127.0.0.1:' . $p, $e, $m, STREAM_SERVER_BIND);
    if ($s !== false) { $srv = $s; $port = $p; break; }
}
if ($srv === false) { echo "BIND FAIL\n"; }
else {
    $cli = stream_socket_client('udp://127.0.0.1:' . $port, $e2, $m2);
    if ($cli === false) { echo "CLIENT FAIL: $m2\n"; }
    else {
        fwrite($cli, "PING-UDP");
        $got = fread($srv, 64);
        echo "got=" . $got . " len=" . strlen($got) . "\n";
        fclose($cli);
    }
    fclose($srv);
}
