<?php
// int list property widened by a float append through a method: the float reads back as int (0), later unshift/sort inherit it
// issue: #76
// item: 25
class P { public array $j; function __construct(array $j) { $this->j = $j; } function add($v) { $this->j[] = $v; } }
$p = new P([3, 1, 2]);
$p->add(1.5);
echo json_encode($p->j), "\n";
array_unshift($p->j, 4);
echo json_encode($p->j), "\n";
