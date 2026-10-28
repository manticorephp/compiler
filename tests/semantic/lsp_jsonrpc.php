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

exit($fail);
