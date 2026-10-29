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

final class Sink
{
    /** @var array<int, array<string, mixed>> */
    public array $msgs = [];
}

function rig(Sink $k, string $root = 'file:///nonexistent-root'): Dispatcher
{
    return rigWs($k, $root)[0];
}

/** @return array{0: Dispatcher, 1: Workspace} */
function rigWs(Sink $k, string $root): array
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $ws = new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
    $s = new Server($ws, function (string $j) use ($k): void {
        $k->msgs[] = json_decode($j, true);
    });
    $d = new Dispatcher();
    $s->register($d);
    $d->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"rootUri":' . json_encode($root) . '}}');
    return [$d, $ws];
}

function open(Dispatcher $d, string $uri, string $text): void
{
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => $text]]]));
}

/** @param array<string, mixed> $extra */
function ask(Dispatcher $d, string $method, string $uri, int $line, int $ch, array $extra = []): mixed
{
    $out = $d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => $method,
        'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => $line, 'character' => $ch]] + $extra]));
    $r = json_decode((string)$out, true);
    return array_key_exists('result', $r) ? $r['result'] : 'ERR ' . $out;
}

$A = "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n";
$B = "<?php\n\$f = new Foo();\n\$f->bar();\n";

check('definition of method call lands in other doc', function () use ($A, $B): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', $A);
    open($d, 'file:///t/b.php', $B);
    $r = ask($d, 'textDocument/definition', 'file:///t/b.php', 2, 5);
    $x = is_array($r) ? ($r[0] ?? null) : null;
    return ($x !== null && $x['uri'] === 'file:///t/a.php'
        && $x['range'] === ['start' => ['line' => 2, 'character' => 20], 'end' => ['line' => 2, 'character' => 23]]) ?: json_encode($r);
});
check('hover over typed variable shows type', function () use ($A, $B): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', $A);
    open($d, 'file:///t/b.php', $B);
    $r = ask($d, 'textDocument/hover', 'file:///t/b.php', 2, 1);
    $v = is_array($r) ? ($r['contents']['value'] ?? '') : '';
    return (str_contains($v, 'Foo') && str_starts_with($v, "```php\n") && isset($r['range'])) ?: json_encode($r);
});
check('hover on a local array: markdown with detail, range in UTF-16 after a multibyte char', function (): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/h.php', "<?php\n\$é = 'é'; \$m = ['a' => 1, 'b' => 'x'];\n");
    $r = ask($d, 'textDocument/hover', 'file:///t/h.php', 1, 11);
    $v = is_array($r) ? ($r['contents']['value'] ?? '') : '';
    $want = "```php\n\$m: array{a: int, b: string}\n```\n\n- `a: int`\n- `b: string`\n\n*inferred*";
    if ($v !== $want) { return json_encode($v); }
    $rg = $r['range'];
    return $rg['start'] === ['line' => 1, 'character' => 10] && $rg['end'] === ['line' => 1, 'character' => 12] ?: json_encode($rg);
});
check('hover fence grows past a backtick run in the type text', function (): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/f.php', "<?php\nclass K {\n    /** @var 'a```b' */\n    public \$p;\n}\n");
    $r = ask($d, 'textDocument/hover', 'file:///t/f.php', 3, 12);
    $v = is_array($r) ? ($r['contents']['value'] ?? '') : '';
    return str_starts_with($v, "````php\nK::\$p: 'a```b'\n````") ?: json_encode($v);
});
check('definition of unknown symbol is null', function (): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/b.php', "<?php\nnope();\n");
    $r = ask($d, 'textDocument/definition', 'file:///t/b.php', 1, 1);
    return $r === null ?: json_encode($r);
});
check('position after multibyte char hits the right token', function (): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction zed() {}\n\$s = 'éé'; zed();\n");
    $r = ask($d, 'textDocument/definition', 'file:///t/a.php', 2, 12);
    $x = is_array($r) ? ($r[0] ?? null) : null;
    return ($x !== null && $x['range']['start'] === ['line' => 1, 'character' => 9]) ?: json_encode($r);
});
check('prelude class definition opens a readable cached file', function (): bool|string {
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    \Lsp\LocationMap::$libText = fn(string $n): ?string => $n === 'core_interfaces.php' ? $src : null;
    try {
        $d = rig(new Sink());
        open($d, 'file:///t/a.php', "<?php\n\$x = new Countable();\n");
        $r = ask($d, 'textDocument/definition', 'file:///t/a.php', 1, 12);
        $x = is_array($r) ? ($r[0] ?? null) : null;
        if ($x === null || !str_starts_with($x['uri'], 'file://')) { return json_encode($r); }
        $text = (string)file_get_contents(\Lsp\Uri::toPath($x['uri']));
        $line = explode("\n", $text)[$x['range']['start']['line']] ?? '';
        return preg_match('/^interface Countable\b/', $line) === 1 ? true : json_encode([$line, $x]);
    } finally {
        \Lsp\LocationMap::$libText = null;
    }
});
check('prelude definition without a text source stays null', function (): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', "<?php\n\$x = new Countable();\n");
    $r = ask($d, 'textDocument/definition', 'file:///t/a.php', 1, 12);
    return $r === null ?: json_encode($r);
});
check('request on a doc that is not open is null', function (): bool|string {
    $d = rig(new Sink());
    return ask($d, 'textDocument/hover', 'file:///t/zzz.php', 0, 0) === null;
});

/** @return string[] */
function uris(mixed $r): array
{
    $o = [];
    foreach (is_array($r) ? $r : [] as $x) { $o[] = $x['uri'] . '@' . $x['range']['start']['line']; }
    sort($o);
    return $o;
}

check('references: open site and closed (indexed) site of a class; declaration toggle', function () use ($A, $B): bool|string {
    $r = sys_get_temp_dir() . '/lspnav' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    $d = rig(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', $A);
    open($d, 'file:///t/b.php', $B);
    $all = uris(ask($d, 'textDocument/references', 'file:///t/a.php', 1, 8, ['context' => ['includeDeclaration' => true]]));
    $nod = uris(ask($d, 'textDocument/references', 'file:///t/a.php', 1, 8, ['context' => ['includeDeclaration' => false]]));
    unlink("$r/c.php"); rmdir($r);
    $want = ['file:///t/a.php@1', 'file:///t/b.php@1', 'file://' . $r . '/c.php@1'];
    sort($want);
    $wantNo = array_values(array_filter($want, fn($x) => $x !== 'file:///t/a.php@1'));
    return ($all === $want && $nod === $wantNo) ?: json_encode([$all, $nod]);
});
check('references: unresolved receiver site not listed', function () use ($A): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', $A);
    open($d, 'file:///t/b.php', "<?php\nfunction mk(\$u) { \$u->bar(); }\n\$f = new Foo();\n\$f->bar();\n");
    $r = uris(ask($d, 'textDocument/references', 'file:///t/b.php', 3, 5, ['context' => ['includeDeclaration' => true]]));
    return $r === ['file:///t/a.php@2', 'file:///t/b.php@3'] ?: json_encode($r);
});

check('references: method called from a CLOSED file is listed; open count restored', function () use ($A): bool|string {
    $r = sys_get_temp_dir() . '/lspnav2' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    $k = new Sink();
    [$d, $ws] = rigWs($k, 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', $A);
    $n = $ws->openFileCount();
    $got = uris(ask($d, 'textDocument/references', 'file:///t/a.php', 2, 22, ['context' => ['includeDeclaration' => true]]));
    $after = $ws->openFileCount();
    unlink("$r/c.php"); rmdir($r);
    $want = ['file:///t/a.php@2', 'file://' . $r . '/c.php@2'];
    sort($want);
    return ($got === $want && $n === $after) ?: json_encode([$got, $n, $after]);
});
check('references: closed files are opened one at a time, results unchanged (#528)', function () use ($A): bool|string {
    $r = sys_get_temp_dir() . '/lspnav4' . getmypid();
    mkdir($r);
    foreach (['c', 'd', 'e', 'f'] as $n) { file_put_contents("$r/$n.php", "<?php\n\$g = new Foo();\n\$g->bar();\n"); }
    file_put_contents("$r/g.php", "<?php\n\$g = new Foo();\n\$g->baz();\n");
    $k = new Sink();
    [$d, $ws] = rigWs($k, 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', $A);
    $seen = [];
    // an observable ClosedFiles over the same workspace
    $c = new \Lsp\ClosedFiles($ws, fn(): array => ['file:///t/a.php' => $A]);
    foreach (['c', 'd', 'e', 'f', 'g'] as $n) { $c->add('file://' . $r . "/$n.php"); }
    $c->setCheck(function () use ($ws, &$seen): void { $seen[] = $ws->openFileCount(); });
    $base = $ws->openFileCount();
    $got = $c->with(fn(): array => $ws->referencesTo('file:///t/a.php', 3, 22));
    $after = $ws->openFileCount();
    foreach (['c', 'd', 'e', 'f', 'g'] as $n) { unlink("$r/$n.php"); }
    rmdir($r);
    $files = array_map(fn($l) => basename($l->file), $got);
    sort($files);
    return ($files === ['a.php', 'c.php', 'd.php', 'e.php', 'f.php'] && $after === $base && $seen !== [] && max($seen) <= $base)
        ?: json_encode([$files, $base, $after, $seen]);
});
check('references: class query opens no files', function () use ($A): bool|string {
    $r = sys_get_temp_dir() . '/lspnav3' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n");
    [$d, $ws] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', $A);
    $got = uris(ask($d, 'textDocument/references', 'file:///t/a.php', 1, 8));
    $cnt = $ws->openFileCount();
    unlink("$r/c.php"); rmdir($r);
    return ($got === ['file:///t/a.php@1', 'file://' . $r . '/c.php@1'] && $cnt === 1) ?: json_encode([$got, $cnt]);
});
check('references: missing context includes the declaration', function () use ($A, $B): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', $A);
    open($d, 'file:///t/b.php', $B);
    $r = uris(ask($d, 'textDocument/references', 'file:///t/b.php', 2, 5));
    return $r === ['file:///t/a.php@2', 'file:///t/b.php@2'] ?: json_encode($r);
});
check('malformed params and negative positions are -32602', function () use ($A): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', $A);
    $bad = [
        ['textDocument' => ['uri' => 'file:///t/a.php']],
        ['textDocument' => ['uri' => 'file:///t/a.php'], 'position' => ['line' => -1, 'character' => 0]],
        ['textDocument' => ['uri' => 'file:///t/a.php'], 'position' => ['line' => 0, 'character' => -3]],
        ['textDocument' => ['uri' => 'file:///t/a.php'], 'position' => ['line' => '1', 'character' => 0]],
    ];
    foreach (['textDocument/hover', 'textDocument/definition', 'textDocument/references'] as $m) {
        foreach ($bad as $p) {
            $r = json_decode((string)$d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => $m, 'params' => $p])), true);
            if (($r['error']['code'] ?? 0) !== -32602) { return $m . ' ' . json_encode($p) . ' -> ' . json_encode($r); }
        }
    }
    return true;
});

exit($fail);
