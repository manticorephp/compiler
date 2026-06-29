<?php
class Node { public ?Node $next = null; public ?string $name = null; }
$n = new Node();
var_dump($n->next);   // NULL
var_dump($n->name);   // NULL
$n->name = "root";
var_dump($n->name);   // string(4) "root"
// null inside a heterogeneous array (boxed cell elements)
$arr = [1, null, "x"];
var_dump($arr);
