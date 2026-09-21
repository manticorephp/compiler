<?php
// crc32 — short cache keys (the CMS pattern) and one long buffer per batch.
// Keys vary with the accumulator so the hash isn't hoisted.
$keys = ["some/template/path/name.tpl", "site_content:42", "chunk:header", "user:session:abcdef"];
$long = str_repeat("abcdefghijklmnopqrstuvwxyz0123456789", 3000);
$m = count($keys);
$acc = 0;
$n = 300000 * $argc;
for ($i = 0; $i < $n; $i++) {
    $acc ^= crc32($keys[($i + $acc) % $m]);
    if (($i & 1023) === 0) { $acc ^= crc32($long); }
}
echo $acc, "\n";
