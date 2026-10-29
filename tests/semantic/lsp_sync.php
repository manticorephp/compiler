<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Lsp\JsonRpc\Dispatcher;
use Lsp\JsonRpc\RpcError;
use Lsp\Position;
use Lsp\Server;
use Lsp\Transport\Stdio;
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

/** @return array{0: Dispatcher, 1: array<int, array<string, mixed>>} */
function rig(string $root, array $caps = []): array
{
    $sent = [];
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $s = new Server(new Workspace(LibrarySymbols::fromParts([$pf], ['strlen'])), function (string $j) use (&$sent): void {
        $sent[] = json_decode($j, true);
    });
    $d = new Dispatcher();
    $s->register($d);
    $d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => $root, 'capabilities' => $caps]]));
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    return [$d, &$sent];
}

function note(Dispatcher $d, string $method, array $params): void
{
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]));
}

function refs(Dispatcher $d, string $uri, int $line, int $ch): array
{
    $out = json_decode((string)$d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'textDocument/references',
        'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => $line, 'character' => $ch], 'context' => ['includeDeclaration' => true]]])), true);
    $u = array_map(fn($l) => basename($l['uri']), $out['result'] ?? []);
    sort($u);
    return $u;
}

check('applyChange: range edit, multi-line, UTF-16 columns', function (): bool|string {
    $t = "<?php\n\$a = '😀x';\necho 1;\n";
    // replace x (after the emoji: 2 UTF-16 units) on line 1 ; columns: $a = ' = 6 chars + emoji 2 = 8 -> x at 8
    $r = Position::applyChange($t, 1, 8, 1, 9, 'yy');
    if ($r !== "<?php\n\$a = '😀yy';\necho 1;\n") { return "emoji: $r"; }
    $r = Position::applyChange($t, 1, 0, 2, 4, '');
    if ($r !== "<?php\n 1;\n") { return "multi: " . json_encode($r); }
    $r = Position::applyChange($t, 9, 9, 9, 9, 'Z');
    return $r === $t . 'Z' ?: 'past end';
});

check('incremental didChange edits the document', function (): bool|string {
    [$d] = rig('file:///nonexistent-root');
    $u = 'file:///t/x.php';
    note($d, 'textDocument/didOpen', ['textDocument' => ['uri' => $u, 'text' => "<?php\nclass Foo {}\n"]]);
    note($d, 'textDocument/didChange', ['textDocument' => ['uri' => $u], 'contentChanges' => [
        ['range' => ['start' => ['line' => 1, 'character' => 6], 'end' => ['line' => 1, 'character' => 9]], 'text' => 'Bar'],
        ['range' => ['start' => ['line' => 1, 'character' => 12], 'end' => ['line' => 1, 'character' => 12]], 'text' => ' function m() {} '],
    ]]);
    $out = json_decode((string)$d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'textDocument/documentSymbol', 'params' => ['textDocument' => ['uri' => $u]]])), true);
    $s = json_encode($out['result']);
    return (str_contains($s, '"Bar"') && str_contains($s, 'm') && !str_contains($s, '"Foo"')) ?: $s;
});

check('didChangeWatchedFiles: create, change, delete keep the index fresh', function (): bool|string {
    $dir = sys_get_temp_dir() . '/lspsync' . getmypid();
    mkdir($dir);
    try {
        file_put_contents("$dir/a.php", "<?php\nclass Foo {}\n");
        [$d] = rig('file://' . $dir);
        $b = 'file://' . $dir . '/b.php';
        note($d, 'textDocument/didOpen', ['textDocument' => ['uri' => $b, 'text' => "<?php new Foo;\n"]]);
        if (refs($d, $b, 0, 11) !== ['a.php', 'b.php']) { return 'start ' . json_encode(refs($d, $b, 0, 11)); }
        file_put_contents("$dir/c.php", "<?php new Foo;\n");
        note($d, 'workspace/didChangeWatchedFiles', ['changes' => [['uri' => 'file://' . $dir . '/c.php', 'type' => 1]]]);
        if (refs($d, $b, 0, 11) !== ['a.php', 'b.php', 'c.php']) { return 'created ' . json_encode(refs($d, $b, 0, 11)); }
        file_put_contents("$dir/c.php", "<?php echo 1;\n");
        note($d, 'workspace/didChangeWatchedFiles', ['changes' => [['uri' => 'file://' . $dir . '/c.php', 'type' => 2]]]);
        if (refs($d, $b, 0, 11) !== ['a.php', 'b.php']) { return 'changed ' . json_encode(refs($d, $b, 0, 11)); }
        unlink("$dir/a.php");
        note($d, 'workspace/didChangeWatchedFiles', ['changes' => [['uri' => 'file://' . $dir . '/a.php', 'type' => 3]]]);
        return refs($d, $b, 0, 11) === ['b.php'] ?: 'deleted ' . json_encode(refs($d, $b, 0, 11));
    } finally {
        foreach (glob("$dir/*.php") ?: [] as $f) { unlink($f); }
        rmdir($dir);
    }
});

check('watcher registered only when the client supports it; client replies are not answered', function (): bool|string {
    [$d, $sent] = rig('file:///nonexistent-root');
    if ($sent !== []) { return 'sent without capability'; }
    [$d, $sent] = rig('file:///nonexistent-root', ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]);
    if (count($sent) !== 1 || $sent[0]['method'] !== 'client/registerCapability') { return 'no registration'; }
    return $d->handle('{"jsonrpc":"2.0","id":"mc-watch-php","result":null}') === null;
});

function io(Dispatcher $d, string $in): array
{
    $i = fopen('php://memory', 'w+');
    fwrite($i, $in);
    rewind($i);
    $o = fopen('php://memory', 'w+');
    (new Stdio(fn(): bool => false))->run($d, $i, $o);
    rewind($o);
    $s = (string)stream_get_contents($o);
    $r = [];
    while ($s !== '') {
        $p = strpos($s, "\r\n\r\n");
        $n = (int)substr($s, 16, $p - 16);
        $r[] = json_decode(substr($s, $p + 4, $n), true);
        $s = substr($s, $p + 4 + $n);
    }
    return $r;
}

check('$/cancelRequest cancels a queued request', function (): bool|string {
    $d = new Dispatcher();
    $d->on('x', fn(array $p): mixed => 'ok');
    $m = fn(int $id) => Stdio::encode(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'x']));
    $r = io($d, $m(1) . Stdio::encode('{"jsonrpc":"2.0","method":"$/cancelRequest","params":{"id":2}}') . $m(2) . $m(3));
    if (count($r) !== 3) { return 'count ' . count($r); }
    return $r[0]['result'] === 'ok' && $r[1]['error']['code'] === RpcError::CANCELLED && $r[2]['result'] === 'ok';
});

check('$/cancelRequest aborts a running request between work units', function (): bool|string {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $d = new Dispatcher();
    $units = 0;
    $d->on('slow', function (array $p) use ($d, $pair, &$units): mixed {
        for ($i = 0; $i < 100; $i++) {
            if ($i === 3) { fwrite($pair[1], Stdio::encode('{"jsonrpc":"2.0","method":"$/cancelRequest","params":{"id":7}}')); }
            $d->cancellation()->check();
            $units++;
        }
        return 'done';
    });
    $d->on('x', fn(array $p): mixed => 'ok');
    fwrite($pair[1], Stdio::encode('{"jsonrpc":"2.0","id":7,"method":"slow"}') . Stdio::encode('{"jsonrpc":"2.0","id":8,"method":"x"}'));
    $out = fopen('php://memory', 'w+');
    $n = 0;
    (new Stdio(function () use (&$n): bool { return $n++ >= 2; }))->run($d, $pair[0], $out);
    rewind($out);
    $s = (string)stream_get_contents($out);
    return str_contains($s, '-32800') && str_contains($s, '"ok"') && $units === 3 ?: "units=$units out=" . substr($s, 0, 200);
});

exit($fail);
