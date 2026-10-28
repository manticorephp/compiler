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
check('prelude class definition is null', function (): bool|string {
    $d = rig(new Sink());
    open($d, 'file:///t/a.php', "<?php\n\$x = new Countable();\n");
    $r = ask($d, 'textDocument/definition', 'file:///t/a.php', 1, 12);
    return $r === null ?: json_encode($r);
});
check('request on a doc that is not open is null', function (): bool|string {
    $d = rig(new Sink());
    return ask($d, 'textDocument/hover', 'file:///t/zzz.php', 0, 0) === null;
});

exit($fail);
