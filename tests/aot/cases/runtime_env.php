<?php

// ini surface, memory, spl_object_hash, iterator helpers and shell_exec.

// php_ini_loaded_file() and memory_limit are DELIBERATE divergences — a
// compiled binary has no php.ini and no arena limit — so they are documented in
// Stdlib/Ini.php rather than asserted against a host-specific oracle here.
var_dump(php_ini_scanned_files());
echo ini_get("precision"), "\n";
var_dump(ini_get("no_such_directive"));
$all = ini_get_all(null, false);
echo isset($all["precision"]) ? "has-precision" : "MISSING", "\n";
$det = ini_get_all();
echo $det["precision"]["global_value"], "\n";

$m = memory_get_usage(true);
echo $m > 0 ? "mem-positive" : "mem-BAD", "\n";
echo memory_get_peak_usage(true) > 0 ? "peak-positive" : "peak-BAD", "\n";

class Thing {}
$a = new Thing();
$b = new Thing();
$ha = spl_object_hash($a);
echo strlen($ha), "\n";
echo $ha === spl_object_hash($a) ? "stable" : "UNSTABLE", "\n";
echo $ha === spl_object_hash($b) ? "COLLIDE" : "distinct", "\n";

function gen(): \Generator
{
    yield "a" => 1;
    yield "b" => 2;
}
$kv = iterator_to_array(gen());
echo $kv["a"], $kv["b"], "\n";
$vals = iterator_to_array(gen(), false);
echo $vals[0], $vals[1], "\n";
echo iterator_count(gen()), "\n";
echo iterator_to_array([5, 6], false)[1], "\n";

$out = shell_exec("printf 'hello\\n'");
echo $out;
echo shell_exec("printf ''") === null ? "null-on-empty\n" : "NOT-NULL\n";
$lines = [];
$rc = 0;
$last = exec("printf 'l1\\nl2\\n'", $lines, $rc);
echo $last, " ", count($lines), " ", $rc, "\n";
