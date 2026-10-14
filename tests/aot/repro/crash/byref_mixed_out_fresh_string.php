<?php
// A fresh local string assigned to a `mixed &$out` parameter is freed when the function returns.
// issue: #233
function mk(): string { return str_repeat('a', 1074) . (string)mt_rand(1, 1); }
function fill(mixed &$output): bool {
    $pem = mk();
    $output = $pem;
    return true;
}
fill($a);
echo strlen($a), "\n";
