<?php
// Legacy "${$v}" variable-variable interpolation fails to compile with "dangling local $ read".
// issue: #541
$a = 'hello';
$v = 'a';
echo "${$v}\n";
