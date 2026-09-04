<?php
// @epic: autoload-semantics
// @why: composer's autoloader registers a PSR-4 resolver; the DI container and
//       doctrine both call class_exists($n, true) expecting it to fire. AOT
//       resolves PSR-4 at compile time (Main.php:1131) and has no runtime
//       autoloader at all.

class CapAlreadyHere {}

$fired = [];
spl_autoload_register(function (string $class) use (&$fired) {
    $fired[] = $class;
});

var_dump(class_exists('CapAlreadyHere', true));
var_dump(class_exists('CapNeverDefined', true));
var_dump($fired);
