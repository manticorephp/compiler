<?php
// Kqueue backend (Darwin) — host smoke test. No macOS php 8.6 oracle exists, so
// the expected output is hand-authored to the RFC semantics (parity deferred).
use Io\Poll\Context;
use Io\Poll\Backend;
use Io\Poll\Event;
$ctx = new Context(Backend::Kqueue);
echo "backend: ", $ctx->getBackend()->name, "\n";
echo "edge: ", ($ctx->getBackend()->supportsEdgeTriggering() ? "yes" : "no"), "\n";
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$a = $pair[0]; $b = $pair[1];
$h = new StreamPollHandle($a);
$w = $ctx->add($h, [Event::Read]);
var_dump($w->isActive());
$r0 = $ctx->wait(0);
echo "ready before write: ", count($r0), "\n";
fwrite($b, "hi");
$r1 = $ctx->wait(0);
echo "ready after write: ", count($r1), "\n";
echo "hasRead: ", ($r1[0]->hasTriggered(Event::Read) ? "yes" : "no"), "\n";
$evs = $r1[0]->getTriggeredEvents();
echo "triggered count: ", count($evs), " first: ", $evs[0]->name, "\n";
$w->remove();
var_dump($w->isActive());
