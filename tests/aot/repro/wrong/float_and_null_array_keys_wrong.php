<?php
// A float or null array key is not converted: php uses key 1 for 1.7 and "" for null
// issue: #404
$a = [];
$k = 1.7;
$a[$k] = 'f';
$a[null] = 'n';
var_dump(array_keys($a));
