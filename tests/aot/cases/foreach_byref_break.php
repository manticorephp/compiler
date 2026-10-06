<?php
// `break` inside a by-ref foreach drops the edits to $v
// issue: #42
$a = [1, 2, 3];
foreach ($a as &$v) { $v = 9; break; }
unset($v);
echo json_encode($a), "\n";
