<?php
// socket_select must not over-release its \Socket array elements across repeated
// calls (a select-driven loop) — the \Socket twin of the stream_select fix.
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
[$a, $b] = $pair;
socket_write($a, "hello");
for ($i = 0; $i < 3; $i++) {
    $r = [$b]; $w = null; $x = null;
    $n = socket_select($r, $w, $x, 0);
    var_dump($n);
    var_dump(count($r));
}
var_dump(socket_read($b, 5));   // sockets survived -> "hello"
echo "done\n";
