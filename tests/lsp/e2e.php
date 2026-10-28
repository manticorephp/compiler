<?php
// Dev tool (run with php): drives `bin/manticore lsp` over real pipes.
$bin = $argv[1] ?? dirname(__DIR__, 2) . '/bin/manticore';
$fail = 0;

function frame(array $m): string
{
    $b = json_encode($m);
    return 'Content-Length: ' . strlen($b) . "\r\n\r\n" . $b;
}

function waitReadable($pipe): bool
{
    $r = [$pipe];
    $w = $e = null;
    return stream_select($r, $w, $e, 20) > 0;
}

function readFrame($pipe): ?array
{
    $len = null;
    while (waitReadable($pipe) && ($l = fgets($pipe)) !== false) {
        $l = rtrim($l, "\r\n");
        if ($l === '') { break; }
        if (stripos($l, 'Content-Length:') === 0) { $len = (int)trim(substr($l, 15)); }
    }
    if ($len === null) { return null; }
    $body = '';
    while (strlen($body) < $len) {
        if (!waitReadable($pipe)) { return null; }
        $c = fread($pipe, $len - strlen($body));
        if ($c === false || $c === '') { return null; }
        $body .= $c;
    }
    return json_decode($body, true);
}

function session(string $bin, array $msgs, int $replies): array
{
    $p = proc_open([$bin, 'lsp'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { throw new RuntimeException('spawn failed'); }
    foreach ($msgs as $m) { fwrite($pipes[0], frame($m)); }
    $got = [];
    for ($i = 0; $i < $replies; $i++) { $got[] = readFrame($pipes[1]); }
    fclose($pipes[0]);
    $rest = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [$got, proc_close($p), $rest, $err];
}

function check(string $name, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : " $extra") . "\n";
    if (!$ok) { $fail = 1; }
}

[$got, $code, $rest, $err] = session($bin, [
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => 'file:///tmp']],
    ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
    ['jsonrpc' => '2.0', 'method' => 'exit'],
], 2);
check('initialize framed', ($got[0]['id'] ?? null) === 1 && ($got[0]['result']['capabilities']['textDocumentSync']['change'] ?? null) === 1, json_encode($got[0]) . $err);
check('shutdown framed', ($got[1]['id'] ?? null) === 2 && array_key_exists('result', $got[1] ?? []), json_encode($got[1]));
check('stdout clean after replies', $rest === '', $rest);
check('exit code 0', $code === 0, "code=$code $err");

[$got, $code, $rest, $err] = session($bin, [
    ['jsonrpc' => '2.0', 'method' => 'exit'],
], 0);
check('exit without shutdown -> 1', $code === 1, "code=$code $err");

$root = sys_get_temp_dir() . '/lspe2e' . getmypid();
mkdir($root);
file_put_contents("$root/lib.php", "<?php\nfunction fromdisk() {}");
$uri = 'file://' . $root . '/main.php';
[$got, $code, $rest, $err] = session($bin, [
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => 'file://' . $root]],
    ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()],
    ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => "<?php\nfromdisk();\nnope_undefined();"]]],
    ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'textDocument/definition', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => 1, 'character' => 2]]],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
    ['jsonrpc' => '2.0', 'method' => 'exit'],
], 4);
unlink("$root/lib.php");
rmdir($root);
$pub = $got[1] ?? [];
$diags = $pub['params']['diagnostics'] ?? [];
check('publishDiagnostics pushed', ($pub['method'] ?? '') === 'textDocument/publishDiagnostics' && ($pub['params']['uri'] ?? '') === $uri, json_encode($got) . $err);
check('diagnostic: indexed fromdisk() ok, nope_undefined() flagged', count($diags) === 1 && $diags[0]['code'] === 'undefined.function' && $diags[0]['range']['start']['line'] === 2, json_encode($diags));
check('definition round trip lands in lib.php', ($got[2]['id'] ?? null) === 3 && ($got[2]['result'][0]['uri'] ?? '') === 'file://' . $root . '/lib.php' && ($got[2]['result'][0]['range']['start'] ?? null) === ['line' => 1, 'character' => 9], json_encode($got[2] ?? null));
check('shutdown after push', ($got[3]['id'] ?? null) === 2 && $code === 0 && $rest === '', json_encode($got[3] ?? null) . " code=$code rest=$rest");

$root = sys_get_temp_dir() . '/lspe2e_rn' . getmypid();
mkdir($root);
file_put_contents("$root/lib.php", "<?php\nfunction fromdisk() {}");
$uri = 'file://' . $root . '/main.php';
[$got, $code, $rest, $err] = session($bin, [
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => 'file://' . $root]],
    ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()],
    ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'text' => "<?php\nfromdisk();"]]],
    ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'textDocument/rename', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => 1, 'character' => 2], 'newName' => 'renamed']],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
    ['jsonrpc' => '2.0', 'method' => 'exit'],
], 4);
unlink("$root/lib.php");
rmdir($root);
$ch = $got[2]['result']['changes'] ?? [];
check('rename round trip edits main.php and lib.php', ($got[2]['id'] ?? null) === 3 && count($ch) === 2
    && ($ch['file://' . $root . '/lib.php'][0]['range']['start'] ?? null) === ['line' => 1, 'character' => 9]
    && ($ch[$uri][0]['newText'] ?? '') === 'renamed', json_encode($got[2] ?? null) . $err);

exit($fail);
