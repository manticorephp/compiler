<?php

// php://output goes through the output layer, so ob_start() captures it.
// php://stdout does NOT — php writes that straight to fd 1. The two used to
// fold to one resource here, which made the difference unrepresentable.
$out = fopen('php://output', 'w');
fwrite($out, "literal-php-output\n");

ob_start();
fwrite($out, "captured-via-output\n");
$c = ob_get_clean();
echo "captured=[", rtrim($c), "] len=", strlen($c), "\n";

// The same name computed at RUNTIME must resolve the same way: the compile-time
// fold only ever sees a literal.
$name = 'php://' . 'output';
$dyn = fopen($name, 'w');
ob_start();
fwrite($dyn, "dynamic\n");
fputs($dyn, "fputs\n");
fprintf($dyn, "fprintf(%d)\n", 5);
$d = ob_get_clean();
echo "dynamic=[", str_replace("\n", "/", $d), "]\n";

var_dump(fflush($out));
var_dump(fclose($out));

// echo and php://output share one ordering, because both funnel.
ob_start();
echo "a";
fwrite($dyn, "b");
echo "c";
$order = ob_get_clean();
echo "order=", $order, "\n";
