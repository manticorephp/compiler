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

/** @return array{0: Dispatcher, 1: Workspace} */
function rigWs(Sink $k, string $root = 'file:///nonexistent-root', int $max = \Lsp\ClosedFiles::MAX, bool $annot = false): array
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $ws = new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
    $s = new Server($ws, function (string $j) use ($k): void {
        $k->msgs[] = json_decode($j, true);
    }, $max);
    $d = new Dispatcher();
    $s->register($d);
    $d->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"rootUri":' . json_encode($root) . ($annot ? ',"capabilities":{"workspace":{"workspaceEdit":{"documentChanges":true,"changeAnnotationSupport":{"groupsOnLabel":true}}}}' : '') . '}}');
    return [$d, $ws];
}

function open(Dispatcher $d, string $uri, string $text, ?int $version = null): void
{
    $td = ['uri' => $uri, 'text' => $text] + ($version === null ? [] : ['version' => $version]);
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => $td]]));
}

/** @param array<string, mixed> $extra */
function ask(Dispatcher $d, string $method, string $uri, int $line, int $ch, array $extra = []): mixed
{
    $out = $d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => $method,
        'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => $line, 'character' => $ch]] + $extra]));
    $r = json_decode((string)$out, true);
    return array_key_exists('result', $r) ? $r['result'] : ['error' => $r['error']['code'] ?? 0, 'message' => $r['error']['message'] ?? ''];
}

/** @return array<int, string> uri:line:start-end:newText */
function flat(mixed $r): array
{
    $o = [];
    foreach (is_array($r) ? ($r['changes'] ?? []) : [] as $u => $es) {
        foreach ($es as $e) {
            $o[] = $u . ':' . $e['range']['start']['line'] . ':' . $e['range']['start']['character'] . '-' . $e['range']['end']['character'] . ':' . $e['newText'];
        }
    }
    return $o;
}

function rn(Dispatcher $d, string $uri, int $l, int $c, string $name): mixed
{
    return ask($d, 'textDocument/rename', $uri, $l, $c, ['newName' => $name]);
}

check('rename function across 2 files, UTF-16 columns', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction helo() {}\n");
    open($d, 'file:///t/b.php', "<?php\n/* é */ helo();\n");
    $r = flat(rn($d, 'file:///t/b.php', 1, 9, 'hi'));
    $want = ['file:///t/a.php:1:9-13:hi', 'file:///t/b.php:1:8-12:hi'];
    return $r === $want ?: json_encode($r);
});
check('prepareRename gives range and placeholder', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction helo() {}\n");
    $r = ask($d, 'textDocument/prepareRename', 'file:///t/a.php', 1, 10);
    return (($r['placeholder'] ?? '') === 'helo' && $r['range']['start']['character'] === 9) ?: json_encode($r);
});
check('rename variable keeps the sigil', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\n\$abc = 1;\necho \$abc;\n");
    $r = flat(rn($d, 'file:///t/a.php', 2, 7, 'xyz'));
    $p = ask($d, 'textDocument/prepareRename', 'file:///t/a.php', 2, 7);
    $want = ['file:///t/a.php:1:1-4:xyz', 'file:///t/a.php:2:6-9:xyz'];
    return ($r === $want && ($p['placeholder'] ?? '') === 'abc') ?: json_encode([$r, $p]);
});
check('rename variable accepts a leading $ in newName', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\n\$abc = 1;\necho \$abc;\n");
    $r = flat(rn($d, 'file:///t/a.php', 1, 2, '$xyz'));
    return ($r[0] ?? '') === 'file:///t/a.php:1:1-4:xyz' ?: json_encode($r);
});
check('rename method reaches a CLOSED file; open count restored', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn1' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    [$d, $ws] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n");
    $n = $ws->openFileCount();
    $raw = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    $got = flat($raw);
    $after = $ws->openFileCount();
    unlink("$r/c.php"); rmdir($r);
    $want = ['file:///t/a.php:2:20-23:baz', 'file://' . $r . '/c.php:2:4-7:baz'];
    return ($got === $want && $n === $after) ?: json_encode([$raw, $n, $after]);
});
check('uncertain string-class-name site: edit returned and showMessage pushed', function (): bool|string {
    $k = new Sink();
    [$d] = rigWs($k);
    open($d, 'file:///t/a.php', "<?php\nclass Foo {}\n\$n = 'Foo';\n\$x = new Foo();\n");
    $raw = rn($d, 'file:///t/a.php', 1, 7, 'Bar');
    $r = flat($raw);
    $m = null;
    foreach ($k->msgs as $x) { if (($x['method'] ?? '') === 'window/showMessage') { $m = $x; } }
    return (count($r) === 2 && $m !== null && $m['params']['type'] === 2
        && str_contains($m['params']['message'], 'string-class-name') && str_contains($m['params']['message'], 'file:///t/a.php:3')) ?: json_encode([$raw, $k->msgs]);
});
check('blocked rename (parse error in workspace file) errors with no edit', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nclass Foo { public function bar() {} }\n");
    open($d, 'file:///t/b.php', "<?php\n\$f = new Foo();\n\$f->bar(;\n");
    $r = rn($d, 'file:///t/a.php', 1, 31, 'baz');
    return (is_array($r) && ($r['error'] ?? 0) === -32602 && str_contains($r['message'], 'parse-error-region')) ?: json_encode($r);
});
check('library symbol: prepareRename null, rename refused', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction f(Countable \$c) {}\n");
    $p = ask($d, 'textDocument/prepareRename', 'file:///t/a.php', 1, 14);
    $r = rn($d, 'file:///t/a.php', 1, 14, 'Other');
    return ($p === null && is_array($r) && ($r['error'] ?? 0) === -32602) ?: json_encode([$p, $r]);
});
check('invalid new name is -32602', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction helo() {}\n");
    $r = rn($d, 'file:///t/a.php', 1, 10, '1bad');
    return (is_array($r) && ($r['error'] ?? 0) === -32602) ?: json_encode($r);
});
check('CRLF file: \r does not shift ranges', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\r\nfunction helo() {}\r\nhelo();\r\n");
    $r = flat(rn($d, 'file:///t/a.php', 2, 1, 'hi'));
    return $r === ['file:///t/a.php:1:9-13:hi', 'file:///t/a.php:2:0-4:hi'] ?: json_encode($r);
});

/** Applies one file's edits (ASCII text) and returns the result. */
function applyEdits(string $text, mixed $r, string $uri): string
{
    $lines = explode("\n", $text);
    $es = is_array($r) ? ($r['changes'][$uri] ?? []) : [];
    usort($es, fn($a, $b) => [$b['range']['start']['line'], $b['range']['start']['character']] <=> [$a['range']['start']['line'], $a['range']['start']['character']]);
    foreach ($es as $e) {
        $l = $e['range']['start']['line'];
        $lines[$l] = substr($lines[$l], 0, $e['range']['start']['character']) . $e['newText'] . substr($lines[$l], $e['range']['end']['character']);
    }
    return implode("\n", $lines);
}

check('rename instance property keeps $ on the declaration, plain on uses', function (): bool|string {
    [$d] = rigWs(new Sink());
    $src = "<?php\nclass A {\n    public \$sp = 1;\n    function m() { return \$this->sp; }\n}\n\$o = new A();\necho \$o->sp;\n";
    open($d, 'file:///t/a.php', $src);
    $r = rn($d, 'file:///t/a.php', 6, 9, 'foo');
    $out = applyEdits($src, $r, 'file:///t/a.php');
    $want = "<?php\nclass A {\n    public \$foo = 1;\n    function m() { return \$this->foo; }\n}\n\$o = new A();\necho \$o->foo;\n";
    return $out === $want ?: $out;
});
check('rename static property keeps $ on declaration and A::$sp', function (): bool|string {
    [$d] = rigWs(new Sink());
    $src = "<?php\nclass A {\n    public static \$sp = 1;\n}\necho A::\$sp;\n";
    open($d, 'file:///t/a.php', $src);
    $r = rn($d, 'file:///t/a.php', 4, 10, '$foo');
    $p = ask($d, 'textDocument/prepareRename', 'file:///t/a.php', 4, 10);
    $out = applyEdits($src, $r, 'file:///t/a.php');
    $want = "<?php\nclass A {\n    public static \$foo = 1;\n}\necho A::\$foo;\n";
    return ($out === $want && ($p['placeholder'] ?? '') === 'sp') ?: json_encode([$out, $p]);
});
check('rename promoted ctor property keeps every $', function (): bool|string {
    [$d] = rigWs(new Sink());
    $src = "<?php\nclass A {\n    function __construct(public int \$sp) {}\n    function m() { return \$this->sp; }\n}\n";
    open($d, 'file:///t/a.php', $src);
    $r = rn($d, 'file:///t/a.php', 3, 34, 'foo');
    $out = applyEdits($src, $r, 'file:///t/a.php');
    $want = "<?php\nclass A {\n    function __construct(public int \$foo) {}\n    function m() { return \$this->foo; }\n}\n";
    return $out === $want ?: json_encode([$out, $r]);
});
check('$-prefixed newName on a function is -32602', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction helo() {}\n");
    $r = rn($d, 'file:///t/a.php', 1, 10, '$x');
    return (is_array($r) && ($r['error'] ?? 0) === -32602) ?: json_encode($r);
});
check('member rename refuses when the closed-file cap is hit', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn2' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    file_put_contents("$r/d.php", "<?php\n\$h = new Foo();\n\$h->bar();\n");
    [$d] = rigWs(new Sink(), 'file://' . $r, 1);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n");
    $got = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    unlink("$r/c.php"); unlink("$r/d.php"); rmdir($r);
    return (is_array($got) && ($got['error'] ?? 0) === -32602 && str_contains($got['message'], 'rename incomplete: 1 workspace files')) ?: json_encode($got);
});
check('files that do not mention the member are not opened and do not count toward the cap', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn2b' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    file_put_contents("$r/d.php", "<?php\n\$h = 1;\n");
    file_put_contents("$r/e.php", "<?php\n\$h = 2;\n");
    [$d] = rigWs(new Sink(), 'file://' . $r, 1);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n");
    $got = flat(rn($d, 'file:///t/a.php', 2, 22, 'baz'));
    unlink("$r/c.php"); unlink("$r/d.php"); unlink("$r/e.php"); rmdir($r);
    return $got === ['file:///t/a.php:2:20-23:baz', 'file://' . $r . '/c.php:2:4-7:baz'] ?: json_encode($got);
});
check('a file over 2 MiB the scan skipped makes every rename refuse', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn3' . getmypid();
    mkdir($r);
    file_put_contents("$r/big.php", "<?php\n" . str_repeat("// pad pad pad pad pad pad pad pad\n", 65000));
    [$d] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', "<?php\nclass Foo {}\n");
    $got = rn($d, 'file:///t/a.php', 1, 8, 'Bar');
    unlink("$r/big.php"); rmdir($r);
    return (is_array($got) && ($got['error'] ?? 0) === -32602 && str_contains($got['message'], 'rename incomplete: 1 workspace files')) ?: json_encode($got);
});
check('a scanned file deleted since the scan is forgotten: member and class renames still work', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn4' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    [$d] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    unlink("$r/c.php");
    open($d, 'file:///t/a.php', "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n");
    $m = flat(rn($d, 'file:///t/a.php', 2, 22, 'baz'));
    $c = flat(rn($d, 'file:///t/a.php', 1, 8, 'Bar'));
    $m2 = flat(rn($d, 'file:///t/a.php', 2, 22, 'baz'));
    rmdir($r);
    return ($m === ['file:///t/a.php:2:20-23:baz'] && $c === ['file:///t/a.php:1:6-9:Bar'] && $m2 === $m) ?: json_encode([$m, $c, $m2]);
});

/** @return array<string, string> uri => text after applying the edits of every file in $files */
function applyAll(array $files, mixed $r): array
{
    $out = [];
    foreach ($files as $u => $t) { $out[$u] = applyEdits($t, $r, $u); }
    return $out;
}

check('namespaced class rename edits only the last segment (use, FQN, short)', function (): bool|string {
    [$d] = rigWs(new Sink());
    $a = "<?php\nnamespace App\\Models;\nclass User {}\n";
    $b = "<?php\nnamespace App\\Http;\nuse App\\Models\\User;\n\$x = new \\App\\Models\\User();\n\$y = new User();\n";
    open($d, 'file:///t/a.php', $a);
    open($d, 'file:///t/b.php', $b);
    $r = rn($d, 'file:///t/b.php', 4, 11, 'Person');
    $o = applyAll(['file:///t/a.php' => $a, 'file:///t/b.php' => $b], $r);
    $wantB = "<?php\nnamespace App\\Http;\nuse App\\Models\\Person;\n\$x = new \\App\\Models\\Person();\n\$y = new Person();\n";
    return ($o['file:///t/a.php'] === "<?php\nnamespace App\\Models;\nclass Person {}\n" && $o['file:///t/b.php'] === $wantB) ?: json_encode([$o, $r]);
});
check('import alias is left alone, the use line is renamed (cursor on the alias too)', function (): bool|string {
    $a = "<?php\nnamespace App\\Models;\nclass User {}\n";
    $b = "<?php\nuse App\\Models\\User as U2;\n\$x = new U2();\n\$y = new \\App\\Models\\User();\n";
    $want = "<?php\nuse App\\Models\\Person as U2;\n\$x = new U2();\n\$y = new \\App\\Models\\Person();\n";
    foreach ([[3, 22], [2, 10]] as [$l, $c]) {
        [$d] = rigWs(new Sink());
        open($d, 'file:///t/a.php', $a);
        open($d, 'file:///t/b.php', $b);
        $r = rn($d, 'file:///t/b.php', $l, $c, 'Person');
        $o = applyAll(['file:///t/a.php' => $a, 'file:///t/b.php' => $b], $r);
        if ($o['file:///t/b.php'] !== $want) { return json_encode([$l, $c, $o, $r]); }
    }
    return true;
});
check('qualified function reference edits only the function name', function (): bool|string {
    [$d] = rigWs(new Sink());
    $a = "<?php\nnamespace App;\nfunction helo() {}\n";
    $b = "<?php\nnamespace X;\nuse function App\\helo;\n\\App\\helo();\nhelo();\n";
    open($d, 'file:///t/a.php', $a);
    open($d, 'file:///t/b.php', $b);
    $r = rn($d, 'file:///t/a.php', 2, 10, 'hi');
    $o = applyAll(['file:///t/a.php' => $a, 'file:///t/b.php' => $b], $r);
    return ($o['file:///t/a.php'] === "<?php\nnamespace App;\nfunction hi() {}\n"
        && $o['file:///t/b.php'] === "<?php\nnamespace X;\nuse function App\\hi;\n\\App\\hi();\nhi();\n") ?: json_encode([$o, $r]);
});
check('rename refuses when a closed file changed on disk after the scan', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn5' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n");
    [$d] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    file_put_contents("$r/c.php", "<?php\n// added\n\$g = new Foo();\n");
    open($d, 'file:///t/a.php', "<?php\nclass Foo {}\n");
    $got = rn($d, 'file:///t/a.php', 1, 8, 'Bar');
    unlink("$r/c.php"); rmdir($r);
    return (is_array($got) && ($got['error'] ?? 0) === -32602 && str_contains($got['message'], 'index out of date for file://')) ?: json_encode($got);
});
check('closing a file without saving re-indexes it from disk', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn6' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n");
    [$d] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    $cu = 'file://' . $r . '/c.php';
    open($d, $cu, "<?php\n\n\n\$g = new Foo();\n");
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => $cu]]]));
    open($d, 'file:///t/a.php', "<?php\nclass Foo {}\n");
    $got = flat(rn($d, 'file:///t/a.php', 1, 8, 'Bar'));
    unlink("$r/c.php"); rmdir($r);
    return $got === ['file:///t/a.php:1:6-9:Bar', $cu . ':1:9-12:Bar'] ?: json_encode($got);
});
check('closing a file that is not on disk drops it from the index', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/ghost.php', "<?php\n\$g = new Foo();\n");
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => 'file:///t/ghost.php']]]));
    open($d, 'file:///t/a.php', "<?php\nclass Foo {}\n");
    $got = flat(rn($d, 'file:///t/a.php', 1, 8, 'Bar'));
    return $got === ['file:///t/a.php:1:6-9:Bar'] ?: json_encode($got);
});
check('didClose of a never-opened uri is a no-op', function (): bool|string {
    [$d] = rigWs(new Sink());
    $out = $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => 'file:///t/never.php']]]));
    open($d, 'file:///t/a.php', "<?php\nfunction helo() {}\n");
    $got = flat(rn($d, 'file:///t/a.php', 1, 10, 'hi'));
    return ($out === null && $got === ['file:///t/a.php:1:9-13:hi']) ?: json_encode([$out, $got]);
});
check('astral character before the target counts as two UTF-16 units', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', "<?php\nfunction helo() {}\n");
    open($d, 'file:///t/b.php', "<?php\n/* \u{1D4B3} */ helo();\n");
    $r = flat(rn($d, 'file:///t/b.php', 1, 9, 'hi'));
    $p = ask($d, 'textDocument/prepareRename', 'file:///t/b.php', 1, 9);
    return ($r === ['file:///t/a.php:1:9-13:hi', 'file:///t/b.php:1:9-13:hi'] && ($p['placeholder'] ?? '') === 'helo') ?: json_encode([$r, $p]);
});
check('closed file with multibyte text converts columns correctly', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn7' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n/* \u{e9} */ \$g = new Foo();\n");
    [$d] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', "<?php\nclass Foo {}\n");
    $got = flat(rn($d, 'file:///t/a.php', 1, 8, 'Bar'));
    unlink("$r/c.php"); rmdir($r);
    return $got === ['file:///t/a.php:1:6-9:Bar', 'file://' . $r . '/c.php:1:17-20:Bar'] ?: json_encode($got);
});
check('client uri with different percent-encoding maps to the scan key', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn8 a+b' . getmypid();
    mkdir($r);
    file_put_contents("$r/a.php", "<?php\nfunction helo() {}\nhelo();\n");
    [$d] = rigWs(new Sink(), \Lsp\Uri::fromPath($r));
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    $client = 'file://' . str_replace(' ', '%20', $r) . '/a.php';
    open($d, $client, "<?php\nfunction helo() {}\nhelo();\n");
    $got = flat(rn($d, $client, 1, 10, 'hi'));
    unlink("$r/a.php"); rmdir($r);
    $key = \Lsp\Uri::fromPath($r . '/a.php');
    return $got === [$key . ':1:9-13:hi', $key . ':2:0-4:hi'] ?: json_encode([$got, $key]);
});
$FOO = "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n";

check('annotating client: unresolved-receiver site is an annotated edit, certain edits are not, versions carried', function () use ($FOO): bool|string {
    $k = new Sink();
    [$d] = rigWs($k, 'file:///nonexistent-root', \Lsp\ClosedFiles::MAX, true);
    open($d, 'file:///t/a.php', $FOO, 7);
    open($d, 'file:///t/b.php', "<?php\nfunction mk(\$u) { \$u->bar(); }\n\$f = new Foo();\n\$f->bar();\n");
    $r = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    if (!is_array($r) || isset($r['changes']) || !isset($r['documentChanges'])) { return json_encode($r); }
    $by = [];
    foreach ($r['documentChanges'] as $dc) { $by[$dc['textDocument']['uri']] = $dc; }
    $a = $by['file:///t/a.php'] ?? null;
    $b = $by['file:///t/b.php'] ?? null;
    if ($a === null || $b === null) { return json_encode($r); }
    $ann = $r['changeAnnotations']['uncertain:unresolved-receiver'] ?? null;
    $bEdits = [];
    foreach ($b['edits'] as $e) { $bEdits[$e['range']['start']['line']] = $e['annotationId'] ?? null; }
    $msg = null;
    foreach ($k->msgs as $x) { if (($x['method'] ?? '') === 'window/showMessage') { $msg = $x['params']['message']; } }
    return ($a['textDocument']['version'] === 7 && $b['textDocument']['version'] === null
        && !isset($a['edits'][0]['annotationId'])
        && $bEdits === [1 => 'uncertain:unresolved-receiver', 3 => null]
        && ($ann['needsConfirmation'] ?? false) === true && ($ann['label'] ?? '') === 'Uncertain site (receiver type unknown)'
        && is_string($msg) && str_contains($msg, 'for confirmation')) ?: json_encode([$r, $msg]);
});
check('client without changeAnnotationSupport: changes only, certain edits, showMessage sent', function () use ($FOO): bool|string {
    $k = new Sink();
    [$d] = rigWs($k);
    open($d, 'file:///t/a.php', $FOO, 7);
    open($d, 'file:///t/b.php', "<?php\nfunction mk(\$u) { \$u->bar(); }\n\$f = new Foo();\n\$f->bar();\n");
    $r = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    $msg = null;
    foreach ($k->msgs as $x) { if (($x['method'] ?? '') === 'window/showMessage') { $msg = $x['params']['message']; } }
    return (flat($r) === ['file:///t/a.php:2:20-23:baz', 'file:///t/b.php:3:4-7:baz'] && !isset($r['documentChanges']) && !isset($r['changeAnnotations'])
        && is_string($msg) && str_contains($msg, 'uncertain')) ?: json_encode([$r, $msg]);
});
check('uncertain site whose text does not spell the name is dropped; UTF-16 before a flagged site', function () use ($FOO): bool|string {
    [$d] = rigWs(new Sink(), 'file:///nonexistent-root', \Lsp\ClosedFiles::MAX, true);
    open($d, 'file:///t/a.php', $FOO);
    open($d, 'file:///t/b.php', "<?php\nfunction mk(\$u, \$n) { /* \u{e9} */ \$u->bar(); \$u->\$n(); }\n");
    $r = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    $edits = [];
    foreach (is_array($r) ? ($r['documentChanges'] ?? []) : [] as $dc) {
        if ($dc['textDocument']['uri'] === 'file:///t/b.php') { $edits = $dc['edits']; }
    }
    return (count($edits) === 1 && $edits[0]['range']['start'] === ['line' => 1, 'character' => 34] && $edits[0]['range']['end']['character'] === 37
        && ($edits[0]['annotationId'] ?? '') === 'uncertain:unresolved-receiver') ?: json_encode($r);
});

check('re-entrant ClosedFiles::with throws',function (): bool|string {
    $ws = rigWs(new Sink())[1];
    $c = new \Lsp\ClosedFiles($ws, fn(): array => []);
    try {
        $c->with(fn() => $c->with(fn() => 1));
    } catch (\LogicException $e) {
        return true;
    }
    return 'no throw';
});

exit($fail);
