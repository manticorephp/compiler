<?php
// fread()/fgets() on a BLOCKING-mode stream_socket_pair() end parks the task on
// the reactor instead of blocking the process: three forked children each write
// after a delay (longest first), and the readers finish in DELAY order, not in
// spawn order. A blocked process would serialise them (A, B, C) and take the
// sum of the delays.

use function Async\async;
use function Async\spawn;

$pairs = [];
$pids = [];
$delays = ['A' => 0.6, 'B' => 0.3, 'C' => 0.05];
foreach ($delays as $name => $delay) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pair[0]);
        usleep((int)($delay * 1000000));
        fwrite($pair[1], $name . "-line1\n" . $name . "-tail");
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    $pairs[$name] = $pair[0];
    $pids[] = $pid;
}
// B stays non-blocking: the same Frame-style loop must see data or EOF, never ''.
stream_set_blocking($pairs['B'], false);

$t0 = hrtime(true);
$order = [];
async(function () use ($pairs, &$order) {
    $tasks = [];
    foreach ($pairs as $name => $s) {
        $tasks[] = spawn(function () use ($name, $s, &$order) {
            $line = fgets($s);
            $rest = '';
            while (true) {
                $chunk = fread($s, 8192);
                if ($chunk === '' || $chunk === false) { break; }
                $rest .= $chunk;
            }
            $order[] = $name;
            return $name . ': ' . rtrim((string)$line) . ' + ' . $rest . ' eof=' . (feof($s) ? 'y' : 'n');
        });
    }
    foreach ($tasks as $t) { echo $t->await(), "\n"; }
});
$ms = (hrtime(true) - $t0) / 1e6;
foreach ($pids as $pid) { pcntl_waitpid($pid, $st); }
echo 'finish order: ', implode(',', $order), "\n";
echo 'concurrent: ', $ms < 900 ? 'yes' : 'no (' . (int)$ms . ' ms)', "\n";
