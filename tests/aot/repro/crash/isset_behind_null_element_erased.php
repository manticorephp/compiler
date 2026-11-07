<?php
// isset($m['x']->l['k']) faults when the element is a literal null beside objects in the same array.
// issue: #586
final class W { public array $l = ['k' => 1]; }
$m = ['a' => new W, 'x' => null];
var_dump(isset($m['x']->l['k']), isset($m['a']->l['k']));
