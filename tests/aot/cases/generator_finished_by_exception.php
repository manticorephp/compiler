<?php
// A generator finished by an uncaught exception is not marked finished
function g(): \Generator { yield 1; throw new Exception('x'); }
$g = g();
try { foreach ($g as $v) {} } catch (Exception $e) { echo "caught\n"; }
var_dump($g->valid());
$g->next();
echo "ok\n";
