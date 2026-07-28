<?php

// Observability: when an async program hangs there has to be a way to ask it what
// it is doing. `Async\dump()` lists every live task — id, label, and what it is
// parked on (fd + direction, timer, channel, select) — and a DeadlockException now
// carries that table instead of just saying "all tasks are asleep".
//
// The fd NUMBER is not printed here (it is arbitrary); the shapes are.

use function Async\async;
use function Async\spawn;

$port = 0;
$server = false;
for ($p = 51300; $p < 51380; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) { $server = $s; $port = $p; break; }
}

$report = async(function () use ($server): string {
    $acc = spawn(function () use ($server): string {
        $c = stream_socket_accept($server, 0.3);
        return $c === false ? 'timeout' : 'conn';
    })->named('acceptor');
    $slp = spawn(function (): int { \Async\delay(0.2); return 1; })->named('sleeper');
    $ch = \Async\channel(0);
    $rcv = spawn(function () use ($ch): int { $ch->recv(); return 2; })->named('receiver');
    spawn(function () use ($ch): void { \Async\delay(0.05); $ch->send(7); })->named('sender');
    \Async\delay(0.02);
    $snap = \Async\dump();
    $acc->await();
    $slp->await();
    $rcv->await();
    return $snap;
});

// Assert on the SHAPE of the report, not on fd numbers or ordering noise.
$lines = explode("\n", trim($report));
var_dump(count($lines) >= 5);
var_dump(str_contains($lines[0], 'live task(s)'));
// Each line is `#id "label" at file:line <what>` — the spawn site is folded in
// by the compiler, so it is machine-specific and only its PRESENCE is asserted.
var_dump(str_contains($report, '"acceptor" at ') && str_contains($report, 'io-read fd='));
var_dump(str_contains($report, '+deadline'));
var_dump(str_contains($report, '"sleeper" at ') && str_contains($report, ' timer'));
var_dump(str_contains($report, '"receiver" at ') && str_contains($report, ' channel'));
var_dump(substr_count($report, '*') === 1);          // exactly one running task

// Outside a scheduler there is nothing to report.
var_dump(\Async\dump() === '');

// A deadlock names the task that can never be woken.
try {
    async(function (): void {
        $ch = \Async\channel(0);
        spawn(function () use ($ch): void { $ch->recv(); })->named('blocked-receiver');
        \Async\delay(0.02);
    });
    echo "no deadlock\n";
} catch (\Async\DeadlockException $e) {
    $m = $e->getMessage();
    var_dump(str_contains($m, 'deadlock'));
    var_dump(str_contains($m, '"blocked-receiver" at ') && str_contains($m, ' channel'));
}

fclose($server);
echo "done\n";
