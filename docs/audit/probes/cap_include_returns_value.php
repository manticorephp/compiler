<?php
// @epic: include-semantics
// @why: config/bundles.php returns an array, config/*.php return closures, and
//       the dumped DI container includes lazy-service files. Parser.php:2319
//       lowers require/include to Expr::null(), so every one of those is a no-op.

$dir = sys_get_temp_dir() . '/mc_cap_inc';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/arr.php', "<?php\nreturn ['a' => 1, 'b' => 2];\n");
file_put_contents($dir . '/fn.php', "<?php\nreturn function (int \$x): int { return \$x * 3; };\n");

$arr = require $dir . '/arr.php';
var_dump($arr);

$fn = require $dir . '/fn.php';
var_dump(is_callable($fn) ? $fn(5) : 'not callable');

@unlink($dir . '/arr.php');
@unlink($dir . '/fn.php');
@rmdir($dir);
