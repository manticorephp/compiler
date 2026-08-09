<?php
// json_escape_heavy — strings dominated by bytes the escaper must rewrite, so
// the SWAR prefilter never takes its clean-8-bytes path and every word falls to
// the slow arm. json_utf8 covers the \uXXXX tail; this one mixes the C0 short
// forms, the quote/backslash/slash trio and multi-byte UTF-8 in one string.
$rows = [];
for ($i = 0; $i < 3000; $i++) {
    $rows[] = "a\"b\\c/d\ne\tf\rg\x01h \xc3\xa9\xe2\x82\xac " . $i;
}
$acc = 0;
$reps = 100 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $s = json_encode($rows);
    $acc += strlen($s);
}
echo $acc, "\n";
