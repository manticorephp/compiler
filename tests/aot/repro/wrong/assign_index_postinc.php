<?php
// `$a[$i] = $i++` evaluation order differs from php
// issue: #28
$a = [];
$i = 0;
$a[$i] = $i++;
echo json_encode($a), "\n";
