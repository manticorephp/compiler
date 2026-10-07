<?php
// An array row is shared without copy-on-write after array_map
// issue: #26
$s = [['s' => 0, 'e' => 'q']];
$t = array_map(fn($i) => $i, $s);
$s[0]['e'] = 'r';
var_dump($t[0]['e']);
