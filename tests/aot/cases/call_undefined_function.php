<?php
$never = getenv("MANTICORE_NEVER_SET_XYZ");
if ($never === "yes") { echo apcu_add("k","v"), "\n"; }
echo "unreached branch cost nothing\n";
try { echo totally_missing_function(1,2), "\n"; } catch (Error $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
var_dump(function_exists("totally_missing_function"), function_exists("strlen"));
function localFn(int $x): int { return $x * 3; }
echo localFn(4), "|", strlen("abcd"), "|", implode(",", [1,2,3]), "\n";
