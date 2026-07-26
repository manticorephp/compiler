<?php
use function Async\async; use function Async\spawn;
$port=0;$server=false;
for($p=52100;$p<52180;$p=$p+1){ $s=@stream_socket_server('tcp://127.0.0.1:'.$p); if($s!==false){$server=$s;$port=$p;break;} }
$r = async(function () use ($server, $port): string {
    // server: accept, wait, then write — so the reader MUST park.
    $srv = spawn(function () use ($server): string {
        $c = stream_socket_accept($server, 2.0);
        if ($c === false) { return 'no-conn'; }
        \Async\delay(0.15);
        fwrite($c, 'hello');
        \Async\delay(0.1);
        fclose($c);
        return 'sent';
    });
    $cli = spawn(function () use ($port): string {
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) { return 'no'; }
        $reader = spawn(function () use ($c): string { $d = fread($c, 5); return $d === false ? 'FAIL' : $d; });
        $v = $reader->await();
        fclose($c);
        \var_dump($v);
        return 'v=[' . (string)$v . '] strlen=' . (string)strlen((string)$v);
    });
    return $srv->await() . ' ' . $cli->await();
});
echo $r, "\n";
