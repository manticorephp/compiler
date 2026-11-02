<?php
// A constant require target built from dirname()/__DIR__/`.` resolves at compile time to the same unit.
$a = require dirname(__DIR__ . '/sub') . '/a_conf.php';
var_dump($a['name']);
$b = include dirname(__FILE__, 1) . '/a_conf.php';
var_dump($b['n']);
$c = include dirname(__DIR__ . '/x/y', 2) . '/a_conf.php';
var_dump($c['n']);
