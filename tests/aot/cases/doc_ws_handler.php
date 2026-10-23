<?php
// Callback style: a Handler on each side, and a Hub that fans a message out.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

final class Greeter implements WS\Handler
{
    public function __construct(private WS\Hub $hub) {}

    public function onOpen(WS\Connection $c): void
    {
        $this->hub->add($c);
        $c->send('welcome');
    }

    public function onMessage(WS\Connection $c, WS\Message $m): void
    {
        $n = $this->hub->broadcast('said: ' . $m->data);
        echo "server: broadcast to $n\n";
    }

    public function onClose(WS\Connection $c, int $code, string $reason): void
    {
        $this->hub->remove($c);
        echo "server: closed with $code '$reason', hub has ", $this->hub->count(), "\n";
    }

    public function onError(WS\Connection $c, \Throwable $e): void
    {
        echo "server: error ", $e->getMessage(), "\n";
    }
}

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l)->acceptWait(0.02);
$hub = new WS\Hub();

async(function () use ($server, $port, $hub) {
    spawn(function () use ($server, $hub) {
        $server->serve(function (\Http\Request $req) use ($hub): \Http\Response {
            return WS\upgrade($req, WS\handler(new Greeter($hub)));
        });
    });

    $ws = WS\connect("ws://127.0.0.1:$port/");
    $m = $ws->receive();
    echo 'client: ', $m->data, "\n";
    $ws->send('hi');
    $m = $ws->receive();
    echo 'client: ', $m->data, "\n";
    $ws->close(4000, 'bye');
    \Async\delay(0.05);
    echo 'client: peer close code ', $ws->closeCode(), "\n";
    $server->stop();
});
