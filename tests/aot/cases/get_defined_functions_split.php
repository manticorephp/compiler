<?php
// get_defined_functions(): the closed set of functions, split as php splits it.
function myHelper(): int { return 1; }
function AnotherOne(): int { return 2; }
$d = get_defined_functions();
echo implode(',', array_keys($d)), "\n";
$u = $d['user']; sort($u);
echo implode(',', $u), "\n";
foreach (['strlen', 'str_replace', 'array_map', 'preg_match', 'json_encode', 'sprintf'] as $f) {
    echo $f, ' ', var_export(in_array($f, $d['internal'], true), true), "\n";
}
echo var_export(in_array('myhelper', $d['internal'], true), true), "\n";
