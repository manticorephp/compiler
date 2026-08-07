<?php
// @epic: include-semantics
// @why: require/include used to lower to null, so config/bundles.php,
//       config/*.php closures and the dumped DI container's data files all
//       evaluated to nothing. The files are build-time present on purpose:
//       an AOT binary compiles its whole program, so a file generated at
//       RUNTIME can never be one of its units.
$arr = require __DIR__ . '/arr.php';
var_dump($arr);

$fn = require __DIR__ . '/fn.php';
var_dump(is_callable($fn) ? $fn(5) : 'not callable');
