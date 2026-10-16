<?php
// addslashes stops at the first NUL byte instead of escaping it as \0.
// issue: #279
$r = addslashes("a\0b'c");
echo strlen($r), ' ', bin2hex($r), "\n";
