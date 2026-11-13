<?php
// function_exists() with a runtime name, and get_defined_functions(), see every user-visible builtin.
foreach (['stripos', 'gc_enable', 'gc_disable', 'gc_enabled', 'in_array', 'array_key_exists', 'range', 'strrev', 'log1p', 'substr_count', 'json_validate', 'class_alias', 'no_such_fn'] as $f) {
    echo $f, '=', var_export(function_exists($f), true), "\n";
}
echo count(array_intersect(['stripos', 'gc_enable', 'in_array'], get_defined_functions()['internal'])), "\n";
