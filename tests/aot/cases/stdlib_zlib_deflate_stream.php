<?php

// Zend's incremental deflate. Output is compared through INFLATE, never byte for
// byte (DEFLATE is a format, not a function). Expected output is php's.

$text = str_repeat("The quick brown fox jumps over the lazy dog. ", 40);
$parts = str_split($text, 97);

foreach ([ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP] as $enc) {
    foreach ([ZLIB_NO_FLUSH, ZLIB_SYNC_FLUSH, ZLIB_FULL_FLUSH, ZLIB_PARTIAL_FLUSH, ZLIB_BLOCK] as $mode) {
        $ctx = deflate_init($enc, ['level' => 6]);
        $z = '';
        foreach ($parts as $p) { $z .= deflate_add($ctx, $p, $mode); }
        $z .= deflate_add($ctx, '', ZLIB_FINISH);
        $back = zlib_decode($z);
        echo "enc=$enc mode=$mode roundtrip=", $back === $text ? 'ok' : 'BAD', "\n";
    }
}

// A sync flush ends on 00 00 ff ff and the stream decodes up to that point.
$ctx = deflate_init(ZLIB_ENCODING_RAW);
$a = deflate_add($ctx, "Hello", ZLIB_SYNC_FLUSH);
echo bin2hex(substr($a, -4)), "\n";
$b = deflate_add($ctx, "Hello", ZLIB_SYNC_FLUSH);
echo bin2hex(substr($b, -4)), "\n";
echo gzinflate($a . $b . deflate_add($ctx, '', ZLIB_FINISH)), "\n";
// Context takeover: the second "Hello" back-references the first, so it is shorter.
echo strlen($b) < strlen($a) ? "takeover\n" : "no takeover\n";

// FULL_FLUSH resets history: a stream cut after it decodes on its own.
$ctx = deflate_init(ZLIB_ENCODING_RAW);
deflate_add($ctx, $text, ZLIB_FULL_FLUSH);
$tail = deflate_add($ctx, $text, ZLIB_FINISH);
echo gzinflate($tail) === $text ? "full-flush independent\n" : "BAD\n";

// Empty input, and reuse after FINISH (php resets the context).
$ctx = deflate_init(ZLIB_ENCODING_GZIP);
$e = deflate_add($ctx, '', ZLIB_FINISH);
echo var_export(gzdecode($e), true), "\n";
$again = deflate_add($ctx, 'again', ZLIB_FINISH);
echo var_export(gzdecode($again), true), "\n";

// window: a stream coded for window w must inflate under an inflater limited to
// 2^w — the input repeats at distances from 45 bytes to ~23 KiB, so a too-far
// back-reference is rejected. zlib refuses window 8 for raw and gzip (false);
// the zlib container takes it as 9, and says so in CMF.
mt_srand(3);
$rnd = '';
for ($i = 0; $i < 20000; $i++) { $rnd .= chr(mt_rand(0, 255)); }
$wd = $text . $rnd . strrev($text) . $rnd . substr($rnd, 0, 700);
for ($w = 9; $w <= 15; $w++) {
    $ctx = deflate_init(ZLIB_ENCODING_RAW, ['window' => $w]);
    $z = deflate_add($ctx, $wd, ZLIB_FINISH);
    $ictx = inflate_init(ZLIB_ENCODING_RAW, ['window' => $w]);
    echo "window=$w ", inflate_add($ictx, $z, ZLIB_FINISH) === $wd ? 'ok' : 'BAD', "\n";
}
echo 'window=8 raw ', var_export(@deflate_init(ZLIB_ENCODING_RAW, ['window' => 8]), true), "\n";
echo 'window=8 gzip ', var_export(@deflate_init(ZLIB_ENCODING_GZIP, ['window' => 8]), true), "\n";
$ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['window' => 8]);
$z = deflate_add($ctx, $text . strrev($text), ZLIB_FINISH);
echo "window=8 zlib ", bin2hex(substr($z, 0, 2)), ' ', gzuncompress($z) === $text . strrev($text) ? 'ok' : 'BAD', "\n";

// Empty data outside FINISH answers '' and touches nothing: no header, no flush
// of buffered input, no 00 00 ff ff.
$ctx = deflate_init(ZLIB_ENCODING_DEFLATE);
echo var_export(deflate_add($ctx, '', ZLIB_SYNC_FLUSH), true), "\n";
$ctx = deflate_init(ZLIB_ENCODING_RAW);
echo var_export(deflate_add($ctx, 'abc', ZLIB_NO_FLUSH), true), "\n";
echo var_export(deflate_add($ctx, '', ZLIB_SYNC_FLUSH), true), "\n";
echo var_export(deflate_add($ctx, '', ZLIB_FULL_FLUSH), true), "\n";
$z = deflate_add($ctx, 'def', ZLIB_SYNC_FLUSH);
echo bin2hex(substr($z, -4)), ' ', gzinflate($z . deflate_add($ctx, '', ZLIB_FINISH)), "\n";

// A long stream: the history slides past 64 KiB and NO_FLUSH codes a block every
// 64 KiB on its own. Blocks of noise repeat far apart, so matches cross calls.
$blk = [];
for ($k = 0; $k < 4; $k++) {
    $s = '';
    for ($i = 0; $i < 20000; $i++) { $s .= chr(mt_rand(0, 255)); }
    $blk[] = $s;
}
$long = '';
foreach ([0, 1, 0, 2, 1, 3, 0, 2, 3, 1] as $k) { $long .= $blk[$k] . $text; }
foreach ([[ZLIB_SYNC_FLUSH, 15], [ZLIB_NO_FLUSH, 15], [ZLIB_SYNC_FLUSH, 9], [ZLIB_NO_FLUSH, 9]] as [$mode, $w]) {
    foreach ([ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP] as $enc) {
        $ctx = deflate_init($enc, ['window' => $w]);
        $z = '';
        foreach (str_split($long, 5000) as $p) { $z .= deflate_add($ctx, $p, $mode); }
        $z .= deflate_add($ctx, '', ZLIB_FINISH);
        $ictx = inflate_init($enc, ['window' => $w]);
        echo 'long ', strlen($long) > 131072 ? '>128K' : 'SHORT', " enc=$enc mode=$mode window=$w ",
            inflate_add($ictx, $z, ZLIB_FINISH) === $long ? 'ok' : 'BAD', "\n";
    }
}

// dictionary
$ctx = deflate_init(ZLIB_ENCODING_RAW, ['dictionary' => 'quick brown fox']);
$z = deflate_add($ctx, 'the quick brown fox', ZLIB_FINISH);
$ictx = inflate_init(ZLIB_ENCODING_RAW, ['dictionary' => 'quick brown fox']);
echo inflate_add($ictx, $z, ZLIB_FINISH), "\n";

// level 0 = stored
$ctx = deflate_init(ZLIB_ENCODING_RAW, ['level' => 0]);
$z = deflate_add($ctx, 'stored bytes', ZLIB_FINISH);
echo gzinflate($z), "\n";

// errors
$bad = [
    fn() => deflate_init(99),
    fn() => deflate_init(ZLIB_ENCODING_RAW, ['level' => 10]),
    fn() => deflate_init(ZLIB_ENCODING_RAW, ['memory' => 0]),
    fn() => deflate_init(ZLIB_ENCODING_RAW, ['window' => 16]),
    fn() => deflate_init(ZLIB_ENCODING_RAW, ['strategy' => 9]),
    fn() => deflate_add(deflate_init(ZLIB_ENCODING_RAW), 'x', 99),
];
foreach ($bad as $f) {
    try { $f(); echo "no error\n"; }
    catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
