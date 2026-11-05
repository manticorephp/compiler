<?php
interface I {} class P implements I {} final class B extends P {} class Q implements I {} 
function f(string $c, string $t) { return is_subclass_of($c, $t); }
$c='B'; $t='I'; var_dump(is_subclass_of($c, $t), f('B','I'), f('Q','I'), f('B','P'), is_a($c,$t,true), f(B::class, I::class));
foreach (['I' => 1, 'P' => 2, 'Nope' => 3] as $i => $d) { var_dump(is_subclass_of('B', $i)); }
