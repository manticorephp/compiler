<?php
// 3 absent levels appended under an erased (mixed-valued) parent: the whole subtree prints as an address
// item: 23
$m = ['a' => 1];
$m['x']['y']['w'][] = 1.5;
echo json_encode($m), "\n";
