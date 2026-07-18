<?php
$e=0;$m='';$e2=0;$m2='';
$path = '/tmp/mc_unix_test.sock';
@unlink($path);
$srv = stream_socket_server('unix://' . $path, $e, $m);
if ($srv === false) { echo "BIND FAIL: $m\n"; }
else {
    $cli = stream_socket_client('unix://' . $path, $e2, $m2);
    if ($cli === false) { echo "CLIENT FAIL: $m2\n"; }
    else {
        fwrite($cli, "PING-UNIX\n");
        $conn = stream_socket_accept($srv, 5);
        $got = fgets($conn);
        echo "got=" . rtrim($got) . " len=" . strlen($got) . "\n";
        fclose($conn); fclose($cli);
    }
    fclose($srv);
    @unlink($path);
}
