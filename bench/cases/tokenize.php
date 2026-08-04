<?php
// @php-skip — prints timings and names __McTok, a prelude-only class.
// ext/tokenizer throughput. The subject is the compiler's own prelude — real
// PHP with strings, heredocs, comments and attributes, not a synthetic string.
//
// The result is data-dependent (a running checksum over every token) so LLVM
// cannot delete the work, and the three phases are timed apart on purpose:
// the packed scanner core, the LEGACY heterogeneous array token_get_all() has
// to materialise, and the object materialisation PhpToken::tokenize() does.
// Most of the gap to Zend's C tokenizer lives in the materialisers, not the
// scan, and lumping them together hides that.
$src = '';
foreach (['array_fns.php', 'var_dump.php', 'exceptions.php', 'datetime.php'] as $f) {
    $p = __DIR__ . '/../../prelude/' . $f;
    if (is_file($p)) { $src .= file_get_contents($p); }
}
if ($src === '') { echo "no prelude source found\n"; return; }
$bytes = strlen($src);

$reps = 6;
$sum = 0;
$ntok = 0;

$t0 = microtime(true);
for ($r = 0; $r < $reps; $r++) {
    $t = __McTok::run($src);
    $ntok = $t->n;
    $sum += $t->n + strlen($t->texts[$t->n - 1]);
}
$tScan = microtime(true) - $t0;

$t0 = microtime(true);
for ($r = 0; $r < $reps; $r++) {
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) { $sum += $tok[0]; } else { $sum += strlen($tok); }
    }
}
$tAll = microtime(true) - $t0;

$t0 = microtime(true);
for ($r = 0; $r < $reps; $r++) {
    foreach (PhpToken::tokenize($src) as $tok) { $sum += $tok->id; }
}
$tObj = microtime(true) - $t0;

$mb = ($bytes * $reps) / 1048576.0;
$mt = ($ntok * $reps) / 1000000.0;
printf("bytes=%d tokens=%d reps=%d\n", $bytes, $ntok, $reps);
printf("scan          %6.3fs  %6.2f MB/s  %6.2f Mtok/s\n", $tScan, $mb / $tScan, $mt / $tScan);
printf("token_get_all %6.3fs  %6.2f MB/s  %6.2f Mtok/s\n", $tAll, $mb / $tAll, $mt / $tAll);
printf("PhpToken      %6.3fs  %6.2f MB/s  %6.2f Mtok/s\n", $tObj, $mb / $tObj, $mt / $tObj);
printf("checksum=%d\n", $sum);
