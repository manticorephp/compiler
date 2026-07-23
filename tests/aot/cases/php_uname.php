<?php
$m = php_uname('m');
echo "machine-nonempty: ", (strlen($m) > 0 ? "yes" : "no"), "\n";
echo "os matches family: ", (php_uname('s') === PHP_OS ? "yes" : "no"), "\n";
$a = php_uname('a');
echo "all-contains-machine: ", (str_contains($a, $m) ? "yes" : "no"), "\n";
