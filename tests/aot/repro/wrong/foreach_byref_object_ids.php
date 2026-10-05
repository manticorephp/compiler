<?php
// by-ref foreach storing a fresh object per element: both elements report object id #1 (php #3 and #1)
// issue: #72
// item: 19
class O {}
$a = [new O, new O];
foreach ($a as &$v) { $v = new stdClass; }
unset($v);
var_dump($a);
