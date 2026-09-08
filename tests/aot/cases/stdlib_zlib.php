<?php

// ext/zlib, pure PHP. Two things are asserted and they are different in kind:
// that streams THE INTERPRETER produced decode here byte for byte (the hex
// literals below came out of php), and that everything this build produces
// round-trips through its own decoder. What is NOT asserted anywhere is that
// gzdeflate() emits php's bytes — DEFLATE is a format, not a function, and two
// conforming encoders disagree on every input while both being right.

$text = "The quick brown fox jumps over the lazy dog. The quick brown fox jumps again.";
$run  = str_repeat('ab', 300);

// Streams php wrote, decoded here.
$phpRaw  = hex2bin('0bc94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb2984e0549c989e9899a70700');
$phpZlib = hex2bin('789c0bc94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb2984e0549c989e9899a7070042a61bd8');
$phpGzip = hex2bin('1f8b08000000000000130bc94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb2984e0549c989e9899a70700782090f24d000000');
$phpRun  = hex2bin('4b4c4a1c85a390ea1000');

var_dump(gzinflate($phpRaw) === $text);
var_dump(gzuncompress($phpZlib) === $text);
var_dump(gzdecode($phpGzip) === $text);
var_dump(gzinflate($phpRun) === $run);
// The sniffing decoder recognises all three containers.
var_dump(zlib_decode($phpRaw) === $text, zlib_decode($phpZlib) === $text, zlib_decode($phpGzip) === $text);

// Round-trip, every level and every container.
$inputs = ['', 'a', 'ab', $text, $run, str_repeat("\x00\xff", 1000), implode('', array_map('chr', range(0, 255)))];
foreach ($inputs as $i => $in) {
    foreach ([0, 1, 6, 9, -1] as $lvl) {
        $ok = gzinflate(gzdeflate($in, $lvl)) === $in
           && gzuncompress(gzcompress($in, $lvl)) === $in
           && gzdecode(gzencode($in, $lvl)) === $in
           && zlib_decode(zlib_encode($in, ZLIB_ENCODING_GZIP, $lvl)) === $in;
        if (!$ok) { echo "ROUND-TRIP FAIL input=$i level=$lvl\n"; }
    }
}
echo "round-trips ok\n";

// The container's own checks: a bad zlib header, a broken gzip CRC, garbage.
var_dump(gzuncompress("\x00\x00zzzz"));
var_dump(gzdecode("nonsense here!!!!!!!"));
var_dump(gzinflate('nonsense'));
var_dump(gzinflate(''));

// A corrupted gzip trailer must not come back as data. Rebuilt rather than
// written through a string offset: $g is string|false here, and an offset
// assignment on a CELL base is a live compiler bug (see the handoff).
$g = gzencode($text, 6);
$cut = strlen($g) - 5;
$g = substr($g, 0, $cut) . chr(ord($g[$cut]) ^ 0xFF) . substr($g, $cut + 1);
var_dump(gzdecode($g));

// max_length bounds the output; php answers false rather than truncating when
// the stream does not fit. (php's exact cut-off is an allocation artifact a few
// percent BELOW the real length, so only clear-cut sizes are compared here.)
$z = gzdeflate($text, 6);
var_dump(gzinflate($z, 5));
var_dump(gzinflate($z, 1000) === $text);
var_dump(gzinflate($z, 0) === $text);

// php's own ValueErrors, argument numbers included.
try { gzdeflate('x', 10); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
try { gzdeflate('x', -2); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
try { gzinflate('x', -1); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
try { gzdeflate('x', 6, 99); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }

echo ZLIB_ENCODING_RAW, ' ', ZLIB_ENCODING_DEFLATE, ' ', ZLIB_ENCODING_GZIP, "\n";
