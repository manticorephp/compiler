<?php
// $c ? [3,4] : [$n,5] is typed list<int> although $n is mixed: the non-int element reads back as a raw word
// issue: #77
// item: 28
function f(bool $c, $n) { $r = $c ? [3, 4] : [$n, 5]; var_dump($r[0]); }
f(false, "x");
f(false, 2.5);
