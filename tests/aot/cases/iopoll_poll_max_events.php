<?php
use Io\Poll\Context;
use Io\Poll\Backend;
use Io\Poll\Event;
$ctx = new Context(Backend::Poll);
$ws = [];
$peers = [];
for ($i = 0; $i < 3; $i++) {
    $p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $peers[$i] = $p[1];
    $ws[$i] = $ctx->add(new StreamPollHandle($p[0]), [Event::Read]);
    fwrite($p[1], "x");
}
echo count($ctx->wait(0, 0, 2)), "\n";
echo count($ctx->wait(0, 0, 2)), "\n";
echo count($ctx->wait(0)), "\n";
$seen = [];
for ($r = 0; $r < 3; $r++) {
    foreach ($ctx->wait(0, 0, 1) as $w) {
        foreach ($ws as $i => $x) { if ($x === $w) { $seen[$i] = true; } }
    }
}
echo count($seen), "\n";
