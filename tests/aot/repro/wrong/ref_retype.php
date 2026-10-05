<?php
// A referenced local whose type changes reads a stale/raw word (`use (&$d)`, `$r = &$d`)
// issue: #59
$d = 1;
$f = function () use (&$d): void { $d = $d * 2; };
$d = 2.0;
$f();
var_dump($d);
$e = 1;
$r = &$e;
$e = 1.5;
var_dump($r);
function t(): void { $d = 1; $r = &$d; $r = "str"; var_dump($d); $r = [1]; var_dump($d); }
t();
