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

function rig(bool $init = true): Dispatcher
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    $ws = new Workspace(LibrarySymbols::fromParts([$pf], ['strlen', 'str_contains']));
    $s = new Server($ws, function (string $j): void {});
    $d = new Dispatcher();
    $s->register($d);
    if ($init) { $d->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"rootUri":"file:///nonexistent-root"}}'); }
    return $d;
}

function open(Dispatcher $d, string $uri, string $text): void
{
    $d->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => $text]]]));
}

/** @return array<string, mixed> the decoded response */
function complete(Dispatcher $d, string $uri, int $line, int $char): array
{
    $out = $d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'textDocument/completion', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => $line, 'character' => $char]]]));
    return json_decode((string)$out, true);
}

$U = 'file:///t/a.php';

check('capability advertises trigger characters', function (): bool|string {
    $d = rig(false);
    $r = json_decode((string)$d->handle('{"jsonrpc":"2.0","id":2,"method":"initialize","params":{"rootUri":"file:///nonexistent-root"}}'), true);
    $c = $r['result']['capabilities']['completionProvider'] ?? null;
    return ($c['triggerCharacters'] ?? null) === ['$', '>', ':', '\\'] ? true : json_encode($c);
});

check('member items carry LSP kinds', function () use ($U): bool|string {
    $d = rig();
    open($d, $U, "<?php\nclass Foo {\n    public \$p;\n    public function m() {}\n    public const K = 1;\n    function t() { \$this->; }\n}\nFoo::\n");
    $r = complete($d, $U, 5, 26)['result'] ?? null;
    $k = [];
    foreach ($r['items'] ?? [] as $i) { $k[$i['label']] = $i['kind']; }
    if (($k['p'] ?? 0) !== 5 || ($k['m'] ?? 0) !== 2 || $r['isIncomplete'] !== false) { return json_encode($r); }
    $s = complete($d, $U, 7, 5)['result'] ?? null;
    $k = [];
    foreach ($s['items'] ?? [] as $i) { $k[$i['label']] = $i['kind']; }
    return ($k['K'] ?? 0) === 21 ? true : json_encode($s);
});

check('function and class kinds, isIncomplete false below cap', function () use ($U): bool|string {
    $d = rig();
    open($d, $U, "<?php\nstr\nCount\n");
    $f = complete($d, $U, 1, 3)['result'] ?? null;
    $c = complete($d, $U, 2, 5)['result'] ?? null;
    $fk = array_column($f['items'] ?? [], 'kind', 'label');
    $ck = array_column($c['items'] ?? [], 'kind', 'label');
    return ($fk['str_contains'] ?? 0) === 3 && ($ck['Countable'] ?? 0) === 8 && $f['isIncomplete'] === false ? true : json_encode([$f, $c]);
});

check('isIncomplete when capped', function () use ($U): bool|string {
    $d = rig();
    $src = "<?php\n";
    for ($i = 0; $i < 250; $i++) { $src .= "function qq_$i() {}\n"; }
    open($d, $U, $src . "qq_\n");
    $r = complete($d, $U, 251, 3)['result'] ?? null;
    return $r['isIncomplete'] === true && count($r['items']) === 200 ? true : 'incomplete=' . json_encode($r['isIncomplete'] ?? null);
});

check('UTF-16 column after a multi-byte character is converted', function () use ($U): bool|string {
    $d = rig();
    open($d, $U, "<?php\nclass Foo { public \$pub; }\nfunction t(Foo \$a) { \$s = 'é'; \$a->; }\n");
    $line = "function t(Foo \$a) { \$s = 'é'; \$a->";
    $r = complete($d, $U, 2, mb_strlen($line, 'UTF-8'))['result'] ?? null;
    $labels = array_column($r['items'] ?? [], 'label');
    return $labels === ['pub'] ? true : json_encode($r);
});

check('non-open uri -> null, missing params -> -32602', function () use ($U): bool|string {
    $d = rig();
    if (complete($d, 'file:///t/none.php', 0, 0)['result'] !== null) { return 'not null'; }
    $r = json_decode((string)$d->handle('{"jsonrpc":"2.0","id":3,"method":"textDocument/completion","params":{}}'), true);
    return ($r['error']['code'] ?? 0) === -32602 ? true : json_encode($r);
});

check('unknown receiver -> empty items, no error', function () use ($U): bool|string {
    $d = rig();
    open($d, $U, "<?php\nfunction t(\$x) { \$x->; }\n");
    $r = complete($d, $U, 1, 21);
    return ($r['result']['items'] ?? null) === [] ? true : json_encode($r);
});

check('sortText on every item, own members before inherited', function () use ($U): bool|string {
    $d = rig();
    open($d, $U, "<?php\nclass P { public function aaa() {} }\nclass C extends P { public function zzz() {} function t() { \$this->; } }\nstr\n");
    $r = complete($d, $U, 2, 67)['result']['items'] ?? [];
    $st = array_column($r, 'sortText', 'label');
    if (($st['zzz'] ?? '') !== '00_zzz' || ($st['aaa'] ?? '') !== '01_aaa') { return json_encode($st); }
    $f = complete($d, $U, 3, 3)['result']['items'] ?? [];
    foreach (array_merge($r, $f) as $i) {
        if (!str_starts_with($i['sortText'] ?? 'x', '0')) { return 'bad sortText ' . json_encode($i); }
    }
    return ($f[0]['sortText'] ?? '') === '02_' . strtolower($f[0]['label']) ? true : json_encode($f[0] ?? null);
});

exit($fail);
