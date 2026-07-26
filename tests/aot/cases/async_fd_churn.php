<?php

// fd RECYCLING under the netpoller: 60 sequential connections through one
// listener inside a scheduler. A stale connWatcher/waiter entry for a closed fd
// would mis-route the wake of a new connection that reused the number.
// fd RECYCLING: 60 sequential connections through one listener inside a scheduler.
// A stale connWatcher / waiter entry for a closed fd would mis-route the wake of a
// NEW connection that reused the number (or leave the loop watching a dead fd).
use function Async\async; use function Async\spawn;
$port=0;$server=false;
for($p=50700;$p<50780;$p=$p+1){ $s=@stream_socket_server('tcp://127.0.0.1:'.$p); if($s!==false){$server=$s;$port=$p;break;} }
$out = async(function () use ($server,$port): string {
    $srv = spawn(function () use ($server): int {
        $n = 0;
        for ($i = 0; $i < 60; $i = $i + 1) {
            $c = stream_socket_accept($server, 2.0);
            if ($c === false) { break; }
            fwrite($c, 'r' . (string)$i);
            fclose($c);
            $n = $n + 1;
        }
        return $n;
    });
    $cli = spawn(function () use ($port): int {
        $ok = 0;
        for ($i = 0; $i < 60; $i = $i + 1) {
            $c = fsockopen('127.0.0.1', $port);
            if ($c === false) { break; }
            $d = fread($c, 8);
            fclose($c);
            if ($d === 'r' . (string)$i) { $ok = $ok + 1; }
        }
        return $ok;
    });
    return (string)$srv->await() . '/' . (string)$cli->await();
});
echo "served/matched: ", $out, "\n";
