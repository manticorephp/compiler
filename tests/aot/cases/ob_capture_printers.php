<?php

// Every render path must reach the buffer, not just `echo` — they are separate
// emit sites and each had its own way of writing to stdout before the funnel.
ob_start();
echo "echo ";
echo 42, " ";
echo 1.5, " ";
echo true, " ";
printf("printf(%s,%d) ", "x", 7);
printf("%e ", 12345.678);
var_dump(1.5);
var_dump([1, "two"]);
print_r([3, "four"]);
var_export(["k" => 1]);
echo " ";
$args = ["v", 9];
vprintf("vprintf(%s,%d)", $args);
print " print";
$captured = ob_get_clean();

echo "LEN=", strlen($captured), "\n";
echo "---\n", $captured, "\n---\n";
echo "level=", ob_get_level(), "\n";

// A boxed binary string in a `mixed` array renders through the tagged-echo
// helper, which used to truncate at the first NUL.
ob_start();
$mixed = ["a\x00b", 5, false, null, 2.5];
foreach ($mixed as $v) { echo $v, ";"; }
$m = ob_get_clean();
echo "mixed len=", strlen($m), "\n";
