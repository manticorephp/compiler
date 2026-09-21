<?php

// php's CLI ignores SIGPIPE at startup (sapi/cli/php_cli.c), so a write to a
// socket whose peer is gone is an EPIPE the stream layer reports — the script
// keeps running. Without that, the kernel's default disposition kills the
// process on the first such send, with everything still in the stdout buffer
// lost: exit 141 and an empty output. `@` keeps php's notice off the oracle.

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
if ($pair === false) {
    echo "no pair\n";
    return;
}
fclose($pair[1]);
$n = @fwrite($pair[0], str_repeat('x', 1048576));
echo 'bytes=', (int)$n, " alive=yes\n";
fclose($pair[0]);

// The same through a TCP loopback connection, and the process is still here.
$srv = stream_socket_server('tcp://127.0.0.1:0');
if ($srv === false) {
    echo "no listener\n";
    return;
}
$c = stream_socket_client('tcp://' . stream_socket_get_name($srv, false));
$a = stream_socket_accept($srv, 1.0);
fclose($a);
fclose($srv);
// The RST answers the first write that reaches the closed peer; the write
// after it is the one the default disposition would have died on.
for ($i = 0; $i < 10; $i = $i + 1) {
    usleep(10000);
    @fwrite($c, str_repeat('y', 65536));
}
echo "tcp alive=yes\n";
fclose($c);
echo "done\n";
