<?php
// The native strtr byte form returns a fresh +1 string (or the input retained when nothing translates): no leak, no double free.
$U = '_ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $L = '-abcdefghijklmnopqrstuvwxyz';
function t(string $p, string $U, string $L): int {
    $a = strtr($p, $U, $L); $b = strtr("lower-case", $U, $L); $c = strtr($p . "X", $U, $L);
    return strlen($a) + strlen($b) + strlen($c);
}
echo t("Content_Type", $U, $L), "\n";
echo strtr("same", "xyz", "abc"), strtr("A_b", $U, $L), "\n";
for ($i = 0; $i < 50000; $i++) { t("X-Forwarded_For" . $i, $U, $L); }
$b = memory_get_usage();
for ($i = 0; $i < 300000; $i++) { t("X-Forwarded_For" . ($i % 9), $U, $L); }
echo (memory_get_usage() - $b) < 4000000 ? "flat" : "leak", "\n";
