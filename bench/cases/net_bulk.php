<?php
// Bulk socket throughput: 16 MiB through fread() over the loopback.
//
// Server and client in ONE process, so this measures the transport and the read
// buffer, not the network or a second runtime. The listener also makes the case
// self-contained: nothing to start, nothing to reach, deterministic output.
//
// This exercises the read path's bulk arm — an empty buffer plus a large ask, so
// __mc_stream_read() recv's straight into the result instead of staging it in
// $rbuf. Without that bypass the same run held ~23 MB RSS instead of ~2 MB.
$port = 0;
$server = false;
for ($p = 49700; $p < 49780; $p++) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) { $server = $s; $port = $p; break; }
}
if ($server === false) { echo "no port\n"; exit(1); }
$client = fsockopen('127.0.0.1', $port);
$conn = stream_socket_accept($server, 5);

$chunk = str_repeat('x', 65536);
$sink = 0;
for ($i = 0; $i < 256; $i++) {          // 256 * 64 KiB = 16 MiB
    fwrite($conn, $chunk);
    $left = 65536;
    while ($left > 0) {
        $r = fread($client, $left);
        if ($r === '') { break; }
        $left -= strlen($r);
        $sink += strlen($r);
    }
}
fclose($client);
fclose($conn);
fclose($server);
echo "bulk sink=", $sink, "\n";        // consumed: nothing here can be folded away
