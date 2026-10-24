<?php
$f = fopen('php://memory', 'r+');
var_dump(is_resource($f));
fclose($f);
var_dump(is_resource($f));
echo gettype($f), "\n";
