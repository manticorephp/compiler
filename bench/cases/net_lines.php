<?php
// Line rate: 50k CRLF header lines through fgets() over the loopback.
//
// The case the read buffer exists for. fgets() on a socket used to cost ONE
// recv(2) PER BYTE — the only way to avoid over-reading without somewhere to keep
// the bytes past the newline. Buffered, a batch arrives in one syscall and the
// lines come out of memory, so this is the number that should move if the
// buffering ever regresses.
//
// Lines are written in batches of 100, so a recv boundary lands mid-batch and the
// remainder must survive to the next fgets() — the read-ahead path, not just the
// happy one.
$port = 0;
$server = false;
for ($p = 49780; $p < 49860; $p++) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) { $server = $s; $port = $p; break; }
}
if ($server === false) { echo "no port\n"; exit(1); }
$client = fsockopen('127.0.0.1', $port);
$conn = stream_socket_accept($server, 5);

$batch = str_repeat("hello world this is a header line\r\n", 100);
$sink = 0;
for ($i = 0; $i < 500; $i++) {          // 500 * 100 = 50k lines
    fwrite($conn, $batch);
    for ($j = 0; $j < 100; $j++) {
        $l = fgets($client);
        if ($l !== false) { $sink += strlen($l); }
    }
}
fclose($client);
fclose($conn);
fclose($server);
echo "lines sink=", $sink, "\n";
