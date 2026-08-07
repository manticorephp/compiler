<?php
// The entry sorts LAST in the directory, which is how an include case is
// written: every other file is a compile unit reached by `require`.

$boot = require __DIR__ . '/a_boot.php';
var_dump($boot);

$nested = require __DIR__ . '/b_nested.php';
var_dump($nested);

$novalue = require __DIR__ . '/c_novalue.php';
var_dump($novalue);

foreach ($GLOBALS['icr_log'] as $line) {
    echo $line, "\n";
}
