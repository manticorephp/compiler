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

function rig(): Dispatcher
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $ws = new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
    $s = new Server($ws, function (string $j): void {});
    $d = new Dispatcher();
    $s->register($d);
    $d->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"rootUri":"file:///nonexistent-root"}}');
    return $d;
}

function symbols(Dispatcher $d, string $uri): mixed
{
    $out = $d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'textDocument/documentSymbol', 'params' => ['textDocument' => ['uri' => $uri]]]));
    $r = json_decode((string)$out, true);
    return array_key_exists('result', $r) ? $r['result'] : 'ERR ' . $out;
}

function open(Dispatcher $d, string $uri, string $text): void
{
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => $text]]]));
}

/** @param array<string, array<string, int>> $r */
function within(array $r, array $in): bool
{
    $k = fn(array $p): int => $p['line'] * 100000 + $p['character'];
    return $k($in['start']) >= $k($r['start']) && $k($in['end']) <= $k($r['end']);
}

$U = 'file:///t/a.php';
$SRC = "<?php\nclass Foo {\n    const X = 1;\n    public \$p;\n    public function m() {}\n}\nenum E { case C; }\ninterface I {}\ntrait T {}\nfunction f() {}\n";

check('kinds mapped, selectionRange inside range', function () use ($U, $SRC): bool|string {
    $d = rig();
    open($d, $U, $SRC);
    $r = symbols($d, $U);
    if (!is_array($r)) { return json_encode($r); }
    $kinds = [];
    $ok = true;
    $walk = function (array $l) use (&$walk, &$kinds, &$ok): void {
        foreach ($l as $s) {
            $kinds[$s['name']] = $s['kind'];
            $ok = $ok && within($s['range'], $s['selectionRange']);
            $walk($s['children']);
        }
    };
    $walk($r);
    $want = ['Foo' => 5, 'X' => 14, 'p' => 7, 'm' => 6, 'E' => 10, 'C' => 22, 'I' => 11, 'T' => 5, 'f' => 12];
    return ($kinds === $want && $ok) ? true : json_encode($kinds) . ($ok ? '' : ' selectionRange outside range');
});
check('selectionRange inside range: multi-const, multi-property, abstract, interface, truncated class', function () use ($U): bool|string {
    $srcs = [
        "<?php\nclass A {\n    const X = 1, Y = 2;\n    public \$a, \$b;\n}\n",
        "<?php\nabstract class B {\n    abstract public function m(int \$x): int;\n    abstract protected function n();\n}\n",
        "<?php\ninterface I {\n    const C = 1;\n    public function m();\n    public function n(): void;\n}\n",
        "<?php\nclass T {\n    const X = 1, Y = 2;\n    public \$a, \$b;\n    public function m(",
    ];
    foreach ($srcs as $i => $src) {
        $d = rig();
        open($d, $U, $src);
        $r = symbols($d, $U);
        if (!is_array($r) || $r === []) { return "case $i: " . json_encode($r); }
        $ok = true;
        $n = 0;
        $walk = function (array $l) use (&$walk, &$ok, &$n): void {
            foreach ($l as $s) {
                $n++;
                $ok = $ok && within($s['range'], $s['selectionRange']);
                $walk($s['children']);
            }
        };
        $walk($r);
        if (!$ok || $n < 3) { return "case $i: ok=" . json_encode($ok) . " symbols=$n " . json_encode($r); }
    }
    return true;
});
check('UTF-16 columns after a multi-byte character', function () use ($U): bool|string {
    $d = rig();
    open($d, $U, "<?php\n/* é */ function été() {}\n");
    $r = symbols($d, $U);
    $sel = $r[0]['selectionRange'] ?? null;
    return ($sel === ['start' => ['line' => 1, 'character' => 17], 'end' => ['line' => 1, 'character' => 20]]) ?: json_encode($r);
});
check('non-open uri -> null', function (): bool|string {
    $d = rig();
    return symbols($d, 'file:///t/none.php') === null;
});
check('missing uri -> -32602', function (): bool|string {
    $d = rig();
    $r = json_decode((string)$d->handle('{"jsonrpc":"2.0","id":3,"method":"textDocument/documentSymbol","params":{}}'), true);
    return ($r['error']['code'] ?? 0) === -32602;
});

exit($fail);
