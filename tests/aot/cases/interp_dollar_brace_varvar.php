<?php
// Legacy "${$v}" variable-variable interpolation fails to compile with "dangling local $ read".
$a = 'hello';
$v = 'a';
echo "${$v}\n";
