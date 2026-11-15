<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Lsp\JsonRpc\Dispatcher;
use Lsp\Server;
use Lsp\WorkspaceScan;
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

final class Sink
{
    /** @var array<int, array<string, mixed>> */
    public array $msgs = [];
}

function rig(Sink $k, string $root = 'file:///nonexistent-root'): Dispatcher
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $s = new Server(new Workspace(LibrarySymbols::fromParts([$pf], ['strlen'])), function (string $j) use ($k): void {
        $k->msgs[] = json_decode($j, true);
    });
    $d = new Dispatcher();
    $s->register($d);
    $d->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"rootUri":' . json_encode($root) . '}}');
    return $d;
}

function open(Dispatcher $d, string $uri, string $text): void
{
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => $text]]]));
}

function change(Dispatcher $d, string $uri, string $text): void
{
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didChange', 'params' => ['textDocument' => ['uri' => $uri], 'contentChanges' => [['text' => $text]]]]));
}

check('undefined function published with range', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    open($d, 'file:///t/a.php', "<?php\nfoo();");
    $m = $k->msgs[0] ?? null;
    if (($m['method'] ?? '') !== 'textDocument/publishDiagnostics' || $m['params']['uri'] !== 'file:///t/a.php') { return json_encode($k->msgs); }
    $x = $m['params']['diagnostics'][0] ?? null;
    return $x !== null && $x['code'] === 'undefined.function' && $x['source'] === 'manticore' && $x['severity'] === 1
        && $x['range'] === ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 5]] ?: json_encode($x);
});
check('didChange fixing text publishes empty array', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    open($d, 'file:///t/a.php', "<?php\nfoo();");
    change($d, 'file:///t/a.php', "<?php\nstrlen('x');");
    $m = $k->msgs[count($k->msgs) - 1];
    return $m['params']['diagnostics'] === [] ?: json_encode($m);
});
check('opening declaring doc re-publishes other doc cleared', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    open($d, 'file:///t/a.php', "<?php\nfoo();");
    $n = count($k->msgs);
    open($d, 'file:///t/b.php', "<?php\nfunction foo() {}");
    $a = null;
    foreach (array_slice($k->msgs, $n) as $m) {
        if ($m['params']['uri'] === 'file:///t/a.php') { $a = $m; }
    }
    return ($a !== null && $a['params']['diagnostics'] === []) ?: json_encode($k->msgs);
});
check('unchanged other doc is not re-published', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    open($d, 'file:///t/a.php', "<?php\nfoo();");
    $n = count($k->msgs);
    open($d, 'file:///t/b.php', "<?php\necho 1;");
    $uris = array_map(fn($m) => $m['params']['uri'], array_slice($k->msgs, $n));
    return $uris === ['file:///t/b.php'] ?: json_encode($uris);
});
check('UTF-16 column after multibyte char', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    open($d, 'file:///t/a.php', "<?php\n\$s = 'é'; foo();");
    $x = $k->msgs[0]['params']['diagnostics'][0] ?? null;
    return ($x !== null && $x['range']['start']['character'] === 10 && $x['range']['end']['character'] === 15) ?: json_encode($x);
});
check('didClose publishes empty', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    open($d, 'file:///t/a.php', "<?php\nfoo();");
    $d->handle('{"jsonrpc":"2.0","method":"textDocument/didClose","params":{"textDocument":{"uri":"file:///t/a.php"}}}');
    $m = $k->msgs[count($k->msgs) - 1];
    return ($m['params']['uri'] === 'file:///t/a.php' && $m['params']['diagnostics'] === []) ?: json_encode($m);
});
check('WorkspaceScan: sorted, skips .git/node_modules, php only', function (): bool|string {
    $r = sys_get_temp_dir() . '/lspscan' . getmypid();
    mkdir("$r/sub/.git", 0777, true);
    mkdir("$r/node_modules");
    file_put_contents("$r/b.php", '<?php');
    file_put_contents("$r/sub/a.php", '<?php');
    file_put_contents("$r/sub/x.txt", 'x');
    file_put_contents("$r/sub/.git/h.php", '<?php');
    file_put_contents("$r/node_modules/n.php", '<?php');
    $got = WorkspaceScan::phpFiles($r);
    array_map('unlink', ["$r/b.php", "$r/sub/a.php", "$r/sub/x.txt", "$r/sub/.git/h.php", "$r/node_modules/n.php"]);
    rmdir("$r/sub/.git"); rmdir("$r/sub"); rmdir("$r/node_modules"); rmdir($r);
    return $got === ["$r/b.php", "$r/sub/a.php"] ?: json_encode($got);
});
check('initialized indexes folder; open doc sees its declarations', function (): bool|string {
    $r = sys_get_temp_dir() . '/lspidx' . getmypid();
    mkdir($r);
    file_put_contents("$r/lib.php", "<?php\nfunction fromdisk() {}");
    file_put_contents("$r/bad.php", "<?php\n\xff\xfe function ((");
    $k = new Sink();
    $d = rig($k, 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file://' . $r . '/main.php', "<?php\nfromdisk();");
    $m = $k->msgs[0] ?? null;
    unlink("$r/lib.php"); unlink("$r/bad.php"); rmdir($r);
    return ($m['params']['diagnostics'] === [] && count($k->msgs) === 1) ?: json_encode($k->msgs);
});

check('published uri keeps the spelling the client opened with', function (): bool|string {
    $k = new Sink();
    $d = rig($k);
    $u = 'file:///t/a%20b/%7Ea.php';
    open($d, $u, "<?php\nfoo();");
    $m = $k->msgs[0] ?? null;
    if (($m['params']['uri'] ?? '') !== $u) { return json_encode($m); }
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => $u]]]));
    $m = $k->msgs[count($k->msgs) - 1];
    return ($m['params']['uri'] ?? '') === $u && $m['params']['diagnostics'] === [] ?: json_encode($m);
});
exit($fail);
