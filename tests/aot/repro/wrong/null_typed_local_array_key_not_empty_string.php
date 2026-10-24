<?php
// A local that is statically null and used as an array key is key 0 instead of ""
// issue: #412
$a = [];
$n = null;
$a[$n] = 'x';
var_dump(array_keys($a));
