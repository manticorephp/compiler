<?php

// MANTICORE-ONLY (needs the fiber scheduler). `fwrite($this->sock, $this->buf)`
// hands the stdlib a BORROW of the slot's string, and on a peer that does not
// read the write parks on back-pressure with that borrow still live. A slot
// the scan let drop on overwrite would then be freed under the parked writer
// by task B's `$this->buf = ''`, and the peer would receive whatever the
// allocator put in the block next. Expected output is php's: every byte of
// the original buffer arrives.

use function Async\async;
use function Async\spawn;
use function Async\delay;

$port = 0;
$listener = false;
for ($p = 52700; $p < 52780; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $listener = $s;
        $port = $p;
        break;
    }
}
if ($listener === false) {
    echo "no free port\n";
    return;
}
stream_set_blocking($listener, false);

final class Sender
{
    public string $buf = '';
    public int $sent = 0;

    public function __construct(private \Resource $sock) {}

    public function send(): void
    {
        $this->sent = fwrite($this->sock, $this->buf);
    }

    public function clear(): void
    {
        $this->buf = '';
    }
}

async(function () use ($listener, $port) {
    $c = fsockopen('127.0.0.1', $port);
    if ($c === false) {
        echo "connect failed\n";
        return;
    }
    stream_set_blocking($c, false);
    $srv = stream_socket_accept($listener, 1.0);
    if ($srv === false) {
        echo "accept failed\n";
        return;
    }
    stream_set_blocking($srv, false);

    // 16 MB: four times Linux's tcp_wmem ceiling, so the write parks on
    // loopback there too (macOS parks at 4 MB already).
    $want = 16 * 1048576;
    $sender = new Sender($c);
    $sender->buf = str_repeat('0123456789abcdef', 1048576);

    spawn(function () use ($sender) {
        $sender->send();
    });
    spawn(function () use ($sender, $want) {
        delay(0.05);
        $sender->clear();
        $junk = [];
        for ($i = 0; $i < 4; $i++) {
            $junk[] = str_repeat('JUNKJUNKJUNKJUNK', 1048576);
        }
        echo 'cleared junk=', count($junk), "\n";
    });

    delay(0.1);
    $got = '';
    while (strlen($got) < $want) {
        $part = fread($srv, 65536);
        if ($part === '') {
            break;
        }
        $got .= $part;
    }
    echo 'got=', strlen($got), ' crc=', crc32($got), "\n";
    echo 'sent=', $sender->sent, "\n";
    fclose($srv);
    fclose($c);
});
echo "done\n";
