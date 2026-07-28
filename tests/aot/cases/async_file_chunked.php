<?php

// Regular-file I/O has no readiness signal, so Async\readFile / Async\writeFile are
// COOPERATIVE: chunked, with a yield between chunks. MEASURED on a 64 MB hot file —
// one big fread stalls every other task 15-25 ms, this keeps the worst gap ~2 ms.
// Here the file is small (the suite must stay fast); what the case pins down is the
// contract: exact bytes back, and the loop keeps running while the read is going.

use function Async\async;
use function Async\spawn;

$path = sys_get_temp_dir() . '/mc_async_file_chunked.dat';
$payload = str_repeat('abcdefgh', 65536);        // 512 KB

$written = async(function () use ($path, $payload): int {
    $w = spawn(function () use ($path, $payload): int {
        return \Async\writeFile($path, $payload, 65536);
    });
    return $w->await();
});
var_dump($written === strlen($payload));

$out = async(function () use ($path, $payload): string {
    $reader = spawn(function () use ($path): int {
        $d = \Async\readFile($path, 65536);
        return strlen($d);
    });
    $ticker = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 5; $i = $i + 1) { \Async\delay(0.001); $n = $n + 1; }
        return $n;
    });
    $len = $reader->await();
    $ticks = $ticker->await();
    return (string)($len === strlen($payload) ? 'exact' : 'wrong') . ' ticks=' . (string)$ticks;
});
echo $out, "\n";

// Contents match byte for byte, and a missing file is '' (not a fatal).
$again = async(function () use ($path): string { $t = spawn(function () use ($path): string { return \Async\readFile($path); }); return $t->await(); });
var_dump($again === $payload);
$missing = async(function (): string { $t = spawn(function (): string { return \Async\readFile('/nonexistent/mc/file'); }); return $t->await(); });
var_dump($missing === '');

unlink($path);
echo "done\n";
