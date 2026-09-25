<?php

// A one-room chat: a static page on `/` with a 20-line inline JS client, and
// a `Hub` broadcasting every message to every other open connection.
//
//   bin/manticore compile examples/http/ws_chat.php -o ws_chat && ./ws_chat
//   open http://127.0.0.1:8090/ in two tabs

use Http\Request;
use Http\Response;
use Http\Server;
use Http\WebSocket as WS;

final class ChatHandler implements WS\Handler
{
    public function __construct(private WS\Hub $hub) {}

    public function onOpen(WS\Connection $c): void
    {
        $this->hub->add($c);
    }

    public function onMessage(WS\Connection $c, WS\Message $m): void
    {
        $this->hub->broadcast($m->data);
    }

    public function onClose(WS\Connection $c, int $code, string $reason): void
    {
        $this->hub->remove($c);
    }

    public function onError(WS\Connection $c, \Throwable $e): void
    {
        \fwrite(\STDERR, $e->getMessage() . "\n");
    }
}

$page = <<<'HTML'
<!doctype html>
<title>chat</title>
<ul id=log></ul>
<input id=msg autofocus placeholder="say something, press enter">
<script>
const log = document.getElementById('log');
const msg = document.getElementById('msg');
const ws = new WebSocket('ws://' + location.host + '/ws');
ws.onmessage = e => {
    const li = document.createElement('li');
    li.textContent = e.data;
    log.appendChild(li);
    li.scrollIntoView();
};
msg.addEventListener('keydown', e => {
    if (e.key === 'Enter' && msg.value !== '') {
        ws.send(msg.value);
        msg.value = '';
    }
});
</script>
HTML;

$hub = new WS\Hub();

(new Server('tcp://127.0.0.1:8090'))
    ->serve(function (Request $req) use ($hub, $page): Response {
        if ($req->path === '/ws') {
            return WS\upgrade($req, WS\handler(new ChatHandler($hub)));
        }
        return (new Response())->type('text/html')->body($page);
    });
