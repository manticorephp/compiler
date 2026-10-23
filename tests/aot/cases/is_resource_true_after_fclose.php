<?php
// After fclose() is_resource() stays true and var_dump still reports a stream (php: false and "resource (closed)")
$f = fopen('php://memory', 'r+');
fclose($f);
var_dump(is_resource($f));
echo gettype($f), "\n";
