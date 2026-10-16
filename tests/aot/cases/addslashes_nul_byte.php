<?php
// addslashes stops at the first NUL byte instead of escaping it as \0.
$r = addslashes("a\0b'c");
echo strlen($r), ' ', bin2hex($r), "\n";
foreach (["", "\0", "\0\0", "'\"\\\0x", str_repeat("a\0'", 9)] as $s) {
    $r = addslashes($s);
    echo strlen($r), ' ', bin2hex($r), "\n";
}
