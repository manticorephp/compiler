<?php
// stream_select must NOT over-release its array elements: calling it repeatedly on
// the same resources (a server's accept loop) must leave them valid. Regression for
// the by-ref \Resource-array rewrite that freed the listener after the first call.
$p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
[$a, $b] = $p;
fwrite($a, "hello");

for ($i = 0; $i < 3; $i++) {
    $r = [$b]; $w = null; $x = null;
    $n = stream_select($r, $w, $x, 0);   // 0 timeout: data ready -> returns at once
    var_dump($n);                        // 1 every iteration (b readable, still alive)
    var_dump(count($r));                 // 1 (the ready one)
}
// The resources survived the loop — read the buffered bytes.
var_dump(fread($b, 5));                   // "hello"
echo "done\n";
