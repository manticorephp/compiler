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
    sort($o);
    return $o;
}

const SRC_A = "<?php\nnamespace App;\nclass Foo {}\n";
const SRC_B = "<?php\nnamespace Use;\nuse App\\Foo;\n/**\n * @param Foo \$a\n * @return \\App\\Foo[]\n */\nfunction f(\$a) {}\n";

check('definition on a docblock class name jumps to the class', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $r = ask($d, 'textDocument/definition', 'file:///t/b.php', 4, 12);
    $u = $r['uri'] ?? ($r[0]['uri'] ?? '');
    $l = $r['range']['start']['line'] ?? ($r[0]['range']['start']['line'] ?? -1);
    return ($u === 'file:///t/a.php' && $l === 2) ?: json_encode($r);
});
check('definition on a fully qualified docblock name', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $r = ask($d, 'textDocument/definition', 'file:///t/b.php', 5, 14);
    $u = $r['uri'] ?? ($r[0]['uri'] ?? '');
    return $u === 'file:///t/a.php' ?: json_encode($r);
});
check('references include docblock sites', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $r = ask($d, 'textDocument/references', 'file:///t/a.php', 2, 8, ['context' => ['includeDeclaration' => true]]);
    $lines = [];
    foreach ((array)$r as $x) { $lines[] = $x['uri'] . ':' . $x['range']['start']['line']; }
    sort($lines);
    return $lines === ['file:///t/a.php:2', 'file:///t/b.php:2', 'file:///t/b.php:4', 'file:///t/b.php:5'] ?: json_encode($lines);
});
check('hover on a docblock class name', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $r = ask($d, 'textDocument/hover', 'file:///t/b.php', 4, 12);
    return str_contains(json_encode($r), 'App\\\\Foo') ?: json_encode($r);
});
check('rename edits docblock sites, tail segment only for FQN', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $r = flat(ask($d, 'textDocument/rename', 'file:///t/a.php', 2, 8, ['newName' => 'Bar']));
    $want = ['file:///t/a.php:2:6-9:Bar', 'file:///t/b.php:2:8-11:Bar', 'file:///t/b.php:4:10-13:Bar', 'file:///t/b.php:5:16-19:Bar'];
    return $r === $want ?: json_encode($r);
});
check('rename started from a docblock name', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $r = flat(ask($d, 'textDocument/rename', 'file:///t/b.php', 4, 12, ['newName' => 'Bar']));
    return count($r) === 4 ?: json_encode($r);
});
check('aliased docblock name is left untouched', function (): bool|string {
    [$d] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', "<?php\nnamespace Use;\nuse App\\Foo as Baz;\n/**\n * @param Baz \$a\n */\nfunction f(\$a) {}\n");
    $r = flat(ask($d, 'textDocument/rename', 'file:///t/a.php', 2, 8, ['newName' => 'Bar']));
    return $r === ['file:///t/a.php:2:6-9:Bar', 'file:///t/b.php:2:8-11:Bar'] ?: json_encode($r);
});
check('no uncertain docblock blocker for an indexed docblock name', function (): bool|string {
    [$d, $ws] = rigWs(new Sink());
    open($d, 'file:///t/a.php', SRC_A);
    open($d, 'file:///t/b.php', SRC_B);
    $b = $ws->renameBlockers('file:///t/b.php', 5, 12);
    foreach ($b as $x) { if (str_starts_with($x, 'docblock')) { return json_encode($b); } }
    return $ws->symbolKindAt('file:///t/b.php', 5, 12) === 'class' ?: 'kind ' . $ws->symbolKindAt('file:///t/b.php', 5, 12);
});

exit($fail);
