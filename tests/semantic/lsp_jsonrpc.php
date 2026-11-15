<?php

require __DIR__ . '/bootstrap.php';

use Lsp\JsonRpc\Dispatcher;

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

function disp(): Dispatcher
{
    $d = new Dispatcher();
    $d->on('x', function (array $p): mixed { return ['ok' => true]; });
    $d->on('boom', function (array $p): mixed { throw new \RuntimeException('boom'); });
    return $d;
}

check('request result, int id', function (): bool|string {
    $r = disp()->handle('{"jsonrpc":"2.0","id":7,"method":"x","params":{"a":1}}');
    if ($r === null) { return 'null'; }
    $j = json_decode($r, true);
    return $j['id'] === 7 && $j['result']['ok'] === true;
});
check('notification -> null', function (): bool {
    return disp()->handle('{"jsonrpc":"2.0","method":"x","params":{}}') === null;
});
check('unknown method', function (): bool|string {
    $r = disp()->handle('{"jsonrpc":"2.0","id":1,"method":"nope"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32601;
});
check('unknown notification -> null', function (): bool {
    return disp()->handle('{"jsonrpc":"2.0","method":"nope"}') === null;
});
check('parse error', function (): bool|string {
    $r = disp()->handle('{bad');
    if ($r === null) { return 'null'; }
    $j = json_decode($r, true);
    return $j['error']['code'] === -32700 && $j['id'] === null;
});
check('handler throws', function (): bool|string {
    $r = disp()->handle('{"jsonrpc":"2.0","id":2,"method":"boom"}');
    if ($r === null) { return 'null'; }
    $j = json_decode($r, true);
    return $j['error']['code'] === -32603 && str_contains($j['error']['message'], 'boom');
});
check('string id echoed', function (): bool|string {
    $r = disp()->handle('{"jsonrpc":"2.0","id":"abc","method":"x"}');
    return $r !== null && json_decode($r, true)['id'] === 'abc';
});

function dx(): Dispatcher
{
    $d = disp();
    $d->on('rpc', function (array $p): mixed { throw new \Lsp\JsonRpc\RpcError(-32002, 'nope'); });
    $d->on('bad', function (array $p): mixed { return "\xff\xfe"; });
    $d->on('nan', function (array $p): mixed { return NAN; });
    $d->on('badmsg', function (array $p): mixed { throw new \RuntimeException("x\xff"); });
    return $d;
}
check('RpcError code passes through', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":1,"method":"rpc"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32002;
});
check('invalid utf8 result -> -32603', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":1,"method":"bad"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32603;
});
check('NaN result -> -32603', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":1,"method":"nan"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32603;
});
check('invalid utf8 error message survives', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":1,"method":"badmsg"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32603;
});
check('array id -> -32600', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":[],"method":"x"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32600;
});
check('bool id -> -32600', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":true,"method":"x"}');
    return $r !== null && json_decode($r, true)['error']['code'] === -32600;
});
check('id 0 echoed as int', function (): bool|string {
    $r = dx()->handle('{"jsonrpc":"2.0","id":0,"method":"x"}');
    return $r !== null && json_decode($r, true)['id'] === 0;
});
check('notification handler throws -> null', function (): bool {
    return dx()->handle('{"jsonrpc":"2.0","method":"boom"}') === null;
});
check('guard throwing Throwable -> -32603', function (): bool {
    $d = new Dispatcher();
    $d->guard(function (string $m): void { throw new \RuntimeException('g'); });
    $r = json_decode((string)$d->handle('{"jsonrpc":"2.0","id":1,"method":"x"}'), true);
    return $r['error']['code'] === -32603;
});

exit($fail);
