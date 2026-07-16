<?php
class B { public array $xs = []; }
$b = new B();
$b->xs[] = "a"; $b->xs[] = "b"; $b->xs[] = "c";
echo count($b->xs), " ", implode(",", $b->xs), "\n";
