<?php

require __DIR__ . '/bootstrap.php';

use Lsp\JsonRpc\Dispatcher;
use Lsp\Transport\Stdio;

$fail = 0;
function check(string $name, callable $fn): void
{
    global $fail;
    try {
        $r = $fn();
        if ($r === true) { echo "PASS $name\n"; return; }
        echo "FAIL $name: " . (is_string($r) ? $r : 'false') . "\n";
    } catch (\Throwable $e) {
        echo "FAIL $name: " . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
    $fail = 1;
}

function runIo(string $input): string
{
    $d = new Dispatcher();
    $d->on('x', function (array $p): mixed { return ['s' => $p['s'] ?? 'ok']; });
    $in = fopen('php://memory', 'w+');
    fwrite($in, $input);
    rewind($in);
    $out = fopen('php://memory', 'w+');
    (new Stdio(function (): bool { return false; }))->run($d, $in, $out);
    rewind($out);
    return (string) stream_get_contents($out);
}

/** @return string[] */
function unframe(string $s): array
{
    $r = [];
    while ($s !== '') {
        $p = strpos($s, "\r\n\r\n");
        if ($p === false || strncmp($s, 'Content-Length: ', 16) !== 0) { $r[] = 'BAD'; break; }
        $n = (int) substr($s, 16, $p - 16);
        $r[] = substr($s, $p + 4, $n);
        $s = substr($s, $p + 4 + $n);
    }
    return $r;
}

$m1 = '{"jsonrpc":"2.0","id":1,"method":"x","params":{"s":"é"}}';
$m2 = '{"jsonrpc":"2.0","id":2,"method":"x"}';

check('encode counts bytes', function (): bool {
    return Stdio::encode('é') === "Content-Length: 2\r\n\r\né";
});
check('two messages, multibyte', function () use ($m1, $m2): bool|string {
    $r = unframe(runIo(Stdio::encode($m1) . Stdio::encode($m2)));
    return count($r) === 2 && json_decode($r[0], true)['result']['s'] === 'é' && json_decode($r[1], true)['id'] === 2;
});
check('extra Content-Type header, lowercase name', function () use ($m2): bool|string {
    $r = unframe(runIo('content-length: ' . strlen($m2) . "\r\nContent-Type: application/vscode-jsonrpc; charset=utf-8\r\n\r\n" . $m2));
    return count($r) === 1 && json_decode($r[0], true)['id'] === 2;
});
check('missing header -> -32700 then resync', function () use ($m2): bool|string {
    $r = unframe(runIo("garbage\r\n\r\n" . Stdio::encode($m2)));
    return count($r) === 2 && json_decode($r[0], true)['error']['code'] === -32700 && json_decode($r[1], true)['id'] === 2;
});
check('EOF mid-body ends quietly', function () use ($m2): bool|string {
    $r = unframe(runIo(Stdio::encode($m2) . 'Content-Length: 50' . "\r\n\r\n" . '{"jsonrpc"'));
    return count($r) === 1;
});
check('shouldStop honoured', function () use ($m2): bool {
    $d = new Dispatcher();
    $in = fopen('php://memory', 'w+');
    fwrite($in, Stdio::encode($m2));
    rewind($in);
    $out = fopen('php://memory', 'w+');
    (new Stdio(function (): bool { return true; }))->run($d, $in, $out);
    rewind($out);
    return stream_get_contents($out) === '';
});

foreach (['99999999999' => 'huge', 'abc' => 'non-numeric', '-5' => 'negative'] as $cl => $label) {
    check("Content-Length $label -> -32700 then resync", function () use ($cl, $m2): bool|string {
        $r = unframe(runIo("Content-Length: $cl\r\n\r\n" . Stdio::encode($m2)));
        return count($r) === 2 && json_decode($r[0], true)['error']['code'] === -32700 && json_decode($r[1], true)['id'] === 2;
    });
}
check('fragment glued before header resyncs', function () use ($m2): bool|string {
    $r = unframe(runIo('junk' . Stdio::encode($m2)));
    return count($r) === 1 && json_decode($r[0], true)['id'] === 2;
});

exit($fail);
