<?php
// stream_select inside a scheduler must not block the loop: a sibling task keeps
// ticking while the select waits, and the ready set still matches poll(2).
use function Async\async; use function Async\spawn;
$port=0;$server=false;
for($p=50900;$p<50980;$p=$p+1){ $s=@stream_socket_server('tcp://127.0.0.1:'.$p); if($s!==false){$server=$s;$port=$p;break;} }
$out = async(function () use ($server,$port): string {
    $srv = spawn(function () use ($server): string {
        $c = stream_socket_accept($server, 2.0);
        if ($c === false) { return 'no-conn'; }
        \Async\delay(0.2);
        fwrite($c, 'payload');
        \Async\delay(0.1);
        fclose($c);
        return 'sent';
    });
    $sel = spawn(function () use ($port): string {
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) { return 'no'; }
        $r = [$c]; $w = null; $e = null;
        $t0 = microtime(true);
        $n = stream_select($r, $w, $e, 2, 0);
        $waited = microtime(true) - $t0;
        $d = $n > 0 ? fread($c, 16) : '';
        fclose($c);
        return 'n=' . (string)$n . ' data=' . (string)$d . ' waited=' . ($waited > 0.1 && $waited < 1.5 ? 'ok' : 'bad');
    });
    $tick = spawn(function (): int {
        $k = 0;
        for ($i = 0; $i < 8; $i = $i + 1) { \Async\delay(0.02); $k = $k + 1; }
        return $k;
    });
    $a = $sel->await();
    $b = $tick->await();
    $srv->await();
    return $a . ' ticks=' . (string)$b;
});
echo $out, "\n";
// A select with a timeout and nothing to read: returns 0, still bounded.
$to = async(function () use ($server): string {
    $t = spawn(function () use ($server): string {
        $r = [$server]; $w = null; $e = null;
        $t0 = microtime(true);
        $n = stream_select($r, $w, $e, 0, 150000);
        $d = microtime(true) - $t0;
        return 'n=' . (string)$n . ' bounded=' . ($d < 1.0 ? 'yes' : 'no');
    });
    return $t->await();
});
echo $to, "\n";
