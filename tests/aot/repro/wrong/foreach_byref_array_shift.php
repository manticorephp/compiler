<?php
// array_shift inside a by-ref foreach diverges from php
// issue: #41
$a = [1, 2, 3, 4];
foreach ($a as &$v) { array_shift($a); }
unset($v);
echo json_encode($a), "\n";
