<?php
use Io\Poll\Context;
use Io\Poll\Backend;
use Io\Poll\Event;
$ctx = new Context(Backend::Epoll);
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$a = $pair[0]; $b = $pair[1];
$h = new StreamPollHandle($a);
$w = $ctx->add($h, [Event::Write]);
$r0 = $ctx->wait(0);
echo "write-ready: ", count($r0), " ", ($r0[0]->hasTriggered(Event::Write) ? "W" : "-"), "\n";
$w->modifyEvents([Event::Read]);
$r1 = $ctx->wait(0);
echo "after modify to Read, ready: ", count($r1), "\n";
fwrite($b, "x");
$r2 = $ctx->wait(0);
echo "read-ready: ", count($r2), " ", ($r2[0]->hasTriggered(Event::Read) ? "R" : "-"), "\n";
