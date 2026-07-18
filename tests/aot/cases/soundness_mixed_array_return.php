<?php
// (1) array with `false` in a string slot -> SIGSEGV in __mir_array_release_str
function mk(bool $f): array {
    if ($f) { return [false, 'loc']; }
    return ['body', ''];
}
$a = mk(false);
var_dump($a[0]);
$b = mk(true);
var_dump($b[0]);
echo "done\n";
