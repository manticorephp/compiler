<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Lsp\JsonRpc\Dispatcher;
use Lsp\Server;
use Semantic\LibrarySymbols;
use Semantic\Workspace;

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

/** @return array{0: Dispatcher, 1: Server} */
function rig(): array
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $s = new Server(new Workspace(LibrarySymbols::fromParts([$pf], ['strlen'])), function (string $j): void {});
    $d = new Dispatcher();
    $s->register($d);
    return [$d, $s];
}

function call(Dispatcher $d, string $json): ?array
{
    $r = $d->handle($json);
    return $r === null ? null : json_decode($r, true);
}

$init = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"rootUri":"file:///tmp/p"}}';
$open = '{"jsonrpc":"2.0","method":"textDocument/didOpen","params":{"textDocument":{"uri":"file:///tmp/p/a.php","text":"<?php echo 1;"}}}';

check('initialize capabilities', function () use ($init): bool|string {
    [$d] = rig();
    $r = call($d, $init);
    return $r['result']['capabilities']['textDocumentSync']['change'] === 1
        && $r['result']['capabilities']['textDocumentSync']['openClose'] === true
        && $r['result']['serverInfo']['name'] === 'manticore';
});
check('request before initialize -> -32002', function (): bool|string {
    [$d] = rig();
    $r = call($d, '{"jsonrpc":"2.0","id":2,"method":"textDocument/hover","params":{}}');
    return $r['error']['code'] === -32002;
});
check('open, change, close ok', function () use ($init, $open): bool|string {
    [$d] = rig();
    call($d, $init);
    call($d, '{"jsonrpc":"2.0","method":"initialized","params":{}}');
    $a = call($d, $open);
    $b = call($d, '{"jsonrpc":"2.0","method":"textDocument/didChange","params":{"textDocument":{"uri":"file:///tmp/p/a.php"},"contentChanges":[{"text":"x"},{"text":"<?php echo 2;"}]}}');
    $c = call($d, '{"jsonrpc":"2.0","method":"textDocument/didClose","params":{"textDocument":{"uri":"file:///tmp/p/a.php"}}}');
    return $a === null && $b === null && $c === null;
});
check('didChange of never-opened uri ignored', function () use ($init): bool|string {
    [$d] = rig();
    call($d, $init);
    return call($d, '{"jsonrpc":"2.0","method":"textDocument/didChange","params":{"textDocument":{"uri":"file:///nope.php"},"contentChanges":[{"text":"x"}]}}') === null
        && call($d, '{"jsonrpc":"2.0","method":"textDocument/didClose","params":{"textDocument":{"uri":"file:///nope.php"}}}') === null;
});
check('shutdown then exit -> 0', function () use ($init): bool|string {
    [$d, $s] = rig();
    call($d, $init);
    $r = call($d, '{"jsonrpc":"2.0","id":3,"method":"shutdown"}');
    if (!array_key_exists('result', $r) || $r['result'] !== null) { return 'shutdown result'; }
    if ($s->exitCode() !== null) { return 'early exit'; }
    call($d, '{"jsonrpc":"2.0","method":"exit"}');
    return $s->exitCode() === 0;
});
check('exit alone -> 1', function (): bool|string {
    [$d, $s] = rig();
    call($d, '{"jsonrpc":"2.0","method":"exit"}');
    return $s->exitCode() === 1;
});
check('request after shutdown -> -32600', function () use ($init): bool|string {
    [$d] = rig();
    call($d, $init);
    call($d, '{"jsonrpc":"2.0","id":3,"method":"shutdown"}');
    $r = call($d, '{"jsonrpc":"2.0","id":4,"method":"textDocument/hover","params":{}}');
    return $r['error']['code'] === -32600;
});
check('cancelRequest accepted', function () use ($init): bool|string {
    [$d] = rig();
    call($d, $init);
    return call($d, '{"jsonrpc":"2.0","method":"$/cancelRequest","params":{"id":1}}') === null;
});
check('didOpen of lib: uri -> no crash, not tracked, nothing published', function () use ($init): bool|string {
    $sent = 0;
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $s = new Server(new Workspace(LibrarySymbols::fromParts([$pf], ['strlen'])), function (string $j) use (&$sent): void { $sent++; });
    $d = new Dispatcher();
    $s->register($d);
    call($d, $init);
    $r = call($d, '{"jsonrpc":"2.0","method":"textDocument/didOpen","params":{"textDocument":{"uri":"lib:x.php","text":"<?php"}}}');
    $c = call($d, '{"jsonrpc":"2.0","method":"textDocument/didChange","params":{"textDocument":{"uri":"lib:x.php"},"contentChanges":[{"text":"x"}]}}');
    return $r === null && $c === null && $sent === 0;
});

exit($fail);
