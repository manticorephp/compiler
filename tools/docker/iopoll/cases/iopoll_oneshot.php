<?php
use Io\Poll\Context;
use Io\Poll\Backend;
use Io\Poll\Event;
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$a = $pair[0]; $b = $pair[1];
$c = new Context(Backend::Epoll);
$w = $c->add(new StreamPollHandle($a), [Event::Read, Event::OneShot]);
echo "watched:";
foreach ($w->getWatchedEvents() as $e) { echo " ", $e->name; }
echo "\n";
fwrite($b, "x");
echo "wait1: ", count($c->wait(0)), "\n";
echo "wait2: ", count($c->wait(0)), "\n";
echo "active: ", ($w->isActive() ? "yes" : "no"), "\n";
