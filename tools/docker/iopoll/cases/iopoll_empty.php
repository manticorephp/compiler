<?php
use Io\Poll\Context;
use Io\Poll\Backend;
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$c = new Context(Backend::Epoll);
try {
    $c->add(new StreamPollHandle($pair[0]), []);
    echo "no throw\n";
} catch (\Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
