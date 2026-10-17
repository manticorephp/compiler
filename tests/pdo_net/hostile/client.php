<?php
// Compiled natively; usage: client <mode> <port>. Prints a stable summary of the failure.
// No php oracle (the fake server targets our driver's checks): expected output is hand-written in hostile.expected.
$mode = $argv[1];
$dsn = 'pgsql:host=127.0.0.1;port=' . $argv[2] . ';dbname=d';
$fds = fn () => count(scandir('/dev/fd'));
// the first socket lazily opens the runtime's poller fd, which stays for the process: warm it up first
$w = stream_socket_server('tcp://127.0.0.1:0');
$wc = stream_socket_client('tcp://' . stream_socket_get_name($w, false));
fclose($wc); fclose($w);
$before = $fds();
$t = microtime(true);
try {
    $db = new PDO($dsn, 'u', 'pw', [PDO::ATTR_TIMEOUT => 3]);
    $db->query('select 1')->fetchAll();
    echo "$mode: NO ERROR\n";
} catch (PDOException $e) {
    $m = (string) $e->errorInfo[2];
    $i = strpos($m, 'failed: ');
    echo $mode, ': ', $e->errorInfo[0], ' ', $i === false ? $m : substr($m, $i + 8), "\n";
}
$e = null; $db = null;
echo microtime(true) - $t < 3.0 ? "fast\n" : "SLOW\n";
echo $fds() === $before ? "no fd leak\n" : "FD LEAK\n";
