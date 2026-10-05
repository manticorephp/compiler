<?php
// `$obj->p = &$d` copies instead of binding a reference
// issue: #58
final class O { public mixed $p = null; }
$o = new O();
$d = 1;
$o->p = &$d;
$d = 2;
echo $o->p, "\n";
