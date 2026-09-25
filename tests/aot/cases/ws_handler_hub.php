<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// The callback layer: a chat Handler over handler() and a Hub. Joins fan out
// to everyone connected, a message reaches all, a throwing onMessage goes to
// onError and closes that client with 1011, onClose runs once per connection.
// Then a Hub holding a closed connection BEFORE a live one: broadcast drops the
// dead one mid-foreach and still reaches the next.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

final class Log
{
    /** @var array<int,string> */
    public static array<int, string> $lines = [];
}

final class Chat implements WS\Handler
{
    public WS\Hub $hub;
    /** @var array<int,int> */
    private array<int, int> $ids = [];
    private int $next = 0;

    public function __construct()
    {
        $this->hub = new WS\Hub();
    }

    public function onOpen(WS\Connection $c): void
    {
        $this->next = $this->next + 1;
        $this->ids[spl_object_id($c)] = $this->next;
        $this->hub->add($c);
        $this->hub->broadcast('join ' . $this->next);
    }

    public function onMessage(WS\Connection $c, WS\Message $m): void
    {
        if ($m->data === 'boom') {
            throw new \RuntimeException('boom');
        }
        $this->hub->broadcast($this->ids[spl_object_id($c)] . ': ' . $m->data);
    }

    public function onClose(WS\Connection $c, int $code, string $reason): void
    {
        $this->hub->remove($c);
        Log::$lines[] = 'close ' . $code;
    }

    public function onError(WS\Connection $c, \Throwable $e): void
    {
        Log::$lines[] = 'error ' . $e->getMessage();
    }
}

function show(string $who, WS\Connection $c): void
{
    $m = $c->receive();
    echo $who, ' ', $m === null ? 'null code=' . $c->closeCode() : $m->data, "\n";
}

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l)->acceptWait(0.02);
$chat = new Chat();

async(function () use ($server, $port, $chat) {
    spawn(function () use ($server, $chat) {
        $server->serve(function (\Http\Request $req) use ($chat): \Http\Response {
            return WS\upgrade($req, WS\handler($chat));
        });
    });

    $url = 'ws://127.0.0.1:' . $port . '/';
    $c1 = WS\connect($url);
    show('c1', $c1);
    $c2 = WS\connect($url);
    show('c2', $c2);
    $c3 = WS\connect($url);
    show('c3', $c3);
    show('c1', $c1);
    show('c1', $c1);
    show('c2', $c2);

    $c2->send('hi');
    show('c1', $c1);
    show('c2', $c2);
    show('c3', $c3);

    $c3->send('boom');
    $m = $c3->receive();
    echo 'c3 code=', $m === null ? $c3->closeCode() : -1, "\n";

    $h2 = new WS\Hub();
    $h2->add($c3);
    $h2->add($c1);
    echo 'h2 sent=', $h2->broadcast('x'), ' count=', $h2->count(), "\n";
    show('c1', $c1);
    show('c2', $c2);

    $c1->close(1000);
    echo 'c1 code=', $c1->closeCode(), "\n";
    for ($i = 0; $i < 100 && count(Log::$lines) < 3; $i++) {
        \Async\delay(0.01);
    }
    echo 'hub sent=', $chat->hub->broadcast('last'), "\n";
    show('c2', $c2);
    foreach (Log::$lines as $line) {
        echo $line, "\n";
    }
    echo 'hub count=', $chat->hub->count(), "\n";
    $c2->close(1000);
    $server->stop();
});
