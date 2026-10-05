<?php
// `$o->arr[] = yield 1;` emits invalid IR
// issue: #19
final class O { public array $arr = []; }
function g(O $o): \Generator { $o->arr[] = yield 1; }
$o = new O();
$g = g($o);
$g->current();
$g->send('v');
echo json_encode($o->arr), "\n";
