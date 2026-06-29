<?php
// Host-target predefined constants. Values are host-dependent, so assert
// shape rather than printing the literal (keeps the golden output portable).
$fam = PHP_OS_FAMILY;
echo ($fam === "Darwin" || $fam === "Linux") ? "family-ok\n" : "family-bad\n";
echo (PHP_OS === $fam) ? "os-matches\n" : "os-differs\n";
echo "[" . PHP_EXTRA_VERSION . "]\n";
var_dump(defined("PHP_OS"));
var_dump(defined("PHP_OS_FAMILY"));
