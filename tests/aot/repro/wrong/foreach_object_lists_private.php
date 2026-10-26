<?php
// foreach over an object from outside its class lists private and protected properties; php lists only the visible ones.
// issue: #487
class A { private int $p = 2; protected int $r = 4; public int $q = 3; }
$k = [];
foreach (new A() as $n => $x) { $k[] = $n; }
echo json_encode($k), "\n";
