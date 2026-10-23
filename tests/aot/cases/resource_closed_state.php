<?php
// A closed stream reports closed through is_resource, gettype and get_debug_type.
foreach (['php://memory', 'php://temp'] as $u) {
    $f = fopen($u, 'r+');
    fwrite($f, 'abc');
    var_dump(is_resource($f));
    echo gettype($f), ' ', get_debug_type($f), "\n";
    var_dump(fclose($f));
    var_dump(is_resource($f));
    echo gettype($f), ' ', get_debug_type($f), "\n";
}
$d = opendir(sys_get_temp_dir());
var_dump(is_resource($d));
closedir($d);
var_dump(is_resource($d));
echo gettype($d), "\n";
