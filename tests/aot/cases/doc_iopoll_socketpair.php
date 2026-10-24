<?php

use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;

$ctx = new Context(Backend::Poll);
[$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$watcher = $ctx->add(new StreamPollHandle($a), [Event::Read], 'reader');
echo "ready before write: ", count($ctx->wait(0)), "\n";

fwrite($b, "ping");
$ready = $ctx->wait(0);
echo "ready after write: ", count($ready), "\n";
echo $ready[0]->getData(), " triggered: ", $ready[0]->getTriggeredEvents()[0]->name, "\n";
echo "payload: ", fread($a, 4), "\n";

$watcher->remove();
var_dump($watcher->isActive());
