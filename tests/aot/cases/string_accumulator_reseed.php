<?php
// Two string-lifetime fixes meet here:
//   * `$s[$i]` mints a FRESH 1-char buffer (__mir_str_char_at), so a consumer
//     that frees its other fresh operands frees this one too;
//   * a string local that takes BOTH a literal (`$out = ''`) and an owned
//     producer is released before each overwrite, instead of being blocked
//     from rc tracking altogether.
// Correctness side: every intermediate must still read back correctly, and a
// value copied out of the accumulator must survive the re-seed.
$src = "Hello, World";
$held = '';
$rows = [];
for ($r = 0; $r < 3; $r++) {
    $out = '';
    for ($i = 0; $i < 5; $i++) {
        $out = $out . $src[$i + $r];
    }
    $rows[] = $out;
    if ($r === 1) {
        $held = $out;
    }
    $out = strtoupper($out);
    $rows[] = $out;
    $out = '';
}
echo implode("|", $rows), "\n";
echo $held, "\n";
echo strlen($held), "\n";

// the .= append form over character reads
$acc = '';
for ($i = 0; $i < strlen($src); $i++) {
    if ($src[$i] === ' ') {
        continue;
    }
    $acc .= $src[$i];
}
echo $acc, "\n";

// a decoder shape: literal seed, call operands, re-seeded per round
$enc = "a%20b+c";
$dec = '';
$i = 0;
$n = strlen($enc);
while ($i < $n) {
    $c = $enc[$i];
    if ($c === '+') {
        $dec = $dec . ' ';
        $i = $i + 1;
    } elseif ($c === '%' && $i + 2 < $n) {
        $dec = $dec . chr((int)hexdec(substr($enc, $i + 1, 2)));
        $i = $i + 3;
    } else {
        $dec = $dec . $c;
        $i = $i + 1;
    }
}
echo "[", $dec, "]\n";
echo urldecode($enc) === $dec ? "same" : "differs", "\n";
