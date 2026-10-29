<?php

// Latency bench for `manticore lsp` over real stdio pipes.
//
//   php -d xdebug.mode=off tools/bench_lsp.php [--bin bin/manticore] [--root DIR] [--file REL] [--n 200] [--nref 30] [--seed 4242]
//
// Starts the server, initializes with rootUri=<root> (default <repo>/src), times the startup scan
// (initialized -> first answered request), opens one file, then times p50/p95/max per operation over
// deterministic positions (seeded shuffle): didChange->publishDiagnostics, hover, definition,
// references (class and member symbols apart; member references run the closed-file pass),
// completion after `->`, documentSymbol. RSS of the server from ps after the scan and at the end.

$o = getopt('', ['bin:', 'root:', 'file:', 'n:', 'nref:', 'seed:']);
$repo = dirname(__DIR__);
$bin = $o['bin'] ?? $repo . '/bin/manticore';
$root = realpath($o['root'] ?? $repo . '/src');
$file = $root . '/' . ($o['file'] ?? 'Semantic/Completer.php');
$N = (int)($o['n'] ?? 200);
$NREF = (int)($o['nref'] ?? 30);
mt_srand((int)($o['seed'] ?? 4242));

function frame(array $m): string
{
    $b = json_encode($m);
    return 'Content-Length: ' . strlen($b) . "\r\n\r\n" . $b;
}

function readable($pipe): bool
{
    $r = [$pipe];
    $w = $e = null;
    return stream_select($r, $w, $e, 20) > 0;
}

function readFrame($pipe): array
{
    $len = null;
    while (readable($pipe) && ($l = fgets($pipe)) !== false) {
        $l = rtrim($l, "\r\n");
        if ($l === '') { break; }
        if (stripos($l, 'Content-Length:') === 0) { $len = (int)trim(substr($l, 15)); }
    }
    if ($len === null) { fwrite(STDERR, "bench: read timeout / EOF\n"); exit(2); }
    $body = '';
    while (strlen($body) < $len) {
        if (!readable($pipe)) { fwrite(STDERR, "bench: body timeout\n"); exit(2); }
        $c = fread($pipe, $len - strlen($body));
        if ($c === false || $c === '') { fwrite(STDERR, "bench: EOF\n"); exit(2); }
        $body .= $c;
    }
    return json_decode($body, true);
}

$proc = proc_open([$bin, 'lsp'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!is_resource($proc)) { fwrite(STDERR, "spawn failed\n"); exit(2); }
$pid = proc_get_status($proc)['pid'];
$id = 0;
$pending = []; // published diagnostics seen while waiting for a reply
function send(array $m): void { global $pipes; fwrite($pipes[0], frame($m)); }
function request(string $method, array $params): array
{
    global $id, $pipes, $pending;
    $my = ++$id;
    send(['jsonrpc' => '2.0', 'id' => $my, 'method' => $method, 'params' => $params]);
    while (true) {
        $m = readFrame($pipes[1]);
        if (($m['id'] ?? null) === $my) { return $m; }
        if (($m['method'] ?? '') === 'textDocument/publishDiagnostics') { $pending[$m['params']['uri']] = true; }
    }
}
function awaitDiagnostics(string $uri): void
{
    global $pipes, $pending;
    if (isset($pending[$uri])) { unset($pending[$uri]); return; }
    while (true) {
        $m = readFrame($pipes[1]);
        if (($m['method'] ?? '') === 'textDocument/publishDiagnostics' && $m['params']['uri'] === $uri) { return; }
    }
}
function rssMb(int $pid): string
{
    return round((int)trim((string)shell_exec('ps -o rss= -p ' . $pid)) / 1024) . ' MB';
}
function pct(array $v, int $p): float
{
    sort($v);
    return $v[min(count($v) - 1, intdiv(count($v) * $p, 100))];
}
function ms(float $x): string { return sprintf('%8.1f', $x); }
function row(string $name, array $t): void
{
    echo sprintf("%-28s n=%-4d p50 %s  p95 %s  max %s ms\n", $name, count($t), ms(pct($t, 50)), ms(pct($t, 95)), ms(max($t)));
}
function timed(callable $f): float
{
    $t = hrtime(true);
    $f();
    return (hrtime(true) - $t) / 1e6;
}

$rootUri = 'file://' . $root;
$uri = 'file://' . $file;
$text = file_get_contents($file);

request('initialize', ['processId' => null, 'rootUri' => $rootUri, 'capabilities' => new stdClass()]);
send(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()]);
$tScan = timed(function () use ($uri) { request('textDocument/documentSymbol', ['textDocument' => ['uri' => 'file:///nonexistent.php']]); });
$tOpen = timed(function () use ($uri, $text) {
    send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text]]]);
    awaitDiagnostics($uri);
});
echo "workspace $rootUri\nfile " . substr($file, strlen($root) + 1) . ' (' . substr_count($text, "\n") . " lines)\n";
echo sprintf("startup scan (initialized -> first reply) %.0f ms; didOpen -> diagnostics %.0f ms\n", $tScan, $tOpen);
$rssScan = rssMb($pid);

// Positions: identifiers, LSP 0-based (line, character); the file is checked to be ASCII below.
$lines = explode("\n", $text);
$ascii = !preg_match('/[^\x00-\x7F]/', $text);
$class = $member = $arrow = [];
foreach ($lines as $ln => $s) {
    if (preg_match_all('/(->|::|\\\\)?\b([A-Za-z_][A-Za-z0-9_]*)/', $s, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $pos = [$ln, $m[2][1]];
            if ($m[1][0] === '->') { $member[] = $pos; $arrow[] = [$ln, $m[2][1]]; }
            elseif ($m[1][0] === '::') { $member[] = $pos; }
            elseif (ctype_upper($m[2][0][0]) && strlen($m[2][0]) > 3) { $class[] = $pos; }
        }
    }
}
$all = array_merge($class, $member);
$pick = function (array $v, int $n): array {
    for ($i = count($v) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$v[$i], $v[$j]] = [$v[$j], $v[$i]]; }
    return array_slice($v, 0, $n);
};
$td = ['uri' => $uri];
$at = fn(array $p) => ['textDocument' => $td, 'position' => ['line' => $p[0], 'character' => $p[1]]];
$run = function (string $name, string $method, array $positions, array $extra = []) use ($at) {
    $t = [];
    foreach ($positions as $p) {
        $t[] = timed(fn() => request($method, $at($p) + $extra));
    }
    row($name, $t);
};

echo "\n";
$run('hover', 'textDocument/hover', $pick($all, $N));
$run('definition', 'textDocument/definition', $pick($all, $N));
$run('references (class)', 'textDocument/references', $pick($class, $NREF), ['context' => ['includeDeclaration' => true]]);
$run('references (member)', 'textDocument/references', $pick($member, $NREF), ['context' => ['includeDeclaration' => true]]);
// completion right after `->`: the cursor sits just past the arrow, before the member name
$run('completion after ->', 'textDocument/completion', array_map(fn($p) => [$p[0], $p[1]], $pick($arrow, $N)));
$t = [];
for ($i = 0; $i < $N; $i++) { $t[] = timed(fn() => request('textDocument/documentSymbol', ['textDocument' => $td])); }
row('documentSymbol', $t);

// didChange -> publishDiagnostics: toggle a trailing comment on a deterministic line (full-text sync)
$t = [];
$ver = 1;
$cur = $text;
foreach ($pick(range(0, count($lines) - 1), $N) as $ln) {
    $l = $lines;
    $l[$ln] .= ($ver % 2 ? ' ' : '');
    $cur = implode("\n", $l);
    $ver++;
    $t[] = timed(function () use ($uri, $cur, $ver) {
        send(['jsonrpc' => '2.0', 'method' => 'textDocument/didChange', 'params' => ['textDocument' => ['uri' => $uri, 'version' => $ver], 'contentChanges' => [['text' => $cur]]]]);
        awaitDiagnostics($uri);
    });
}
row('didChange -> diagnostics', $t);

echo "\nRSS after scan " . $rssScan . ', at end ' . rssMb($pid) . ($ascii ? '' : "  (file not ASCII: columns are bytes)") . "\n";
request('shutdown', []);
send(['jsonrpc' => '2.0', 'method' => 'exit']);
fclose($pipes[0]);
proc_close($proc);
