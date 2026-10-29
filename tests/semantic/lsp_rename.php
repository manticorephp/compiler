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
function rigWs(Sink $k, string $root = 'file:///nonexistent-root', int $max = \Lsp\ClosedFiles::MAX): array
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $ws = new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
    $s = new Server($ws, function (string $j) use ($k): void {
        $k->msgs[] = json_decode($j, true);
    }, $max);
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
    file_put_contents("$r/d.php", "<?php\n\$h = 1;\n");
    [$d] = rigWs(new Sink(), 'file://' . $r, 1);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    open($d, 'file:///t/a.php', "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n");
    $got = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    unlink("$r/c.php"); unlink("$r/d.php"); rmdir($r);
    return (is_array($got) && ($got['error'] ?? 0) === -32602 && str_contains($got['message'], 'rename incomplete: 2 workspace files')) ?: json_encode($got);
});
check('member rename refuses when a scanned file became unreadable', function (): bool|string {
    $r = sys_get_temp_dir() . '/lsprn3' . getmypid();
    mkdir($r);
    file_put_contents("$r/c.php", "<?php\n\$g = new Foo();\n\$g->bar();\n");
    [$d] = rigWs(new Sink(), 'file://' . $r);
    $d->handle('{"jsonrpc":"2.0","method":"initialized","params":{}}');
    unlink("$r/c.php");
    open($d, 'file:///t/a.php', "<?php\nclass Foo {\n    public function bar(): int { return 1; }\n}\n");
    $got = rn($d, 'file:///t/a.php', 2, 22, 'baz');
    rmdir($r);
    return (is_array($got) && ($got['error'] ?? 0) === -32602 && str_contains($got['message'], 'rename incomplete: 1 workspace files')) ?: json_encode($got);
});

exit($fail);
