<?php

// Zend's incremental deflate. Output is compared through INFLATE, never byte for
// byte (DEFLATE is a format, not a function). Expected output is php's.

function show(string $label, $v): void
{
    echo $label, ': ', is_string($v) ? strlen($v) . ' bytes' : var_export($v, true), "\n";
}

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

// window: every window must round-trip. zlib refuses 8 for raw and gzip; the
// zlib container takes it as 9, and says so in CMF.
for ($w = 9; $w <= 15; $w++) {
    $ctx = deflate_init(ZLIB_ENCODING_RAW, ['window' => $w]);
    $z = deflate_add($ctx, $text . strrev($text), ZLIB_FINISH);
    echo "window=$w ", gzinflate($z) === $text . strrev($text) ? 'ok' : 'BAD', "\n";
}
$ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['window' => 8]);
$z = deflate_add($ctx, $text . strrev($text), ZLIB_FINISH);
echo "window=8 zlib ", bin2hex(substr($z, 0, 2)), ' ', gzuncompress($z) === $text . strrev($text) ? 'ok' : 'BAD', "\n";

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
