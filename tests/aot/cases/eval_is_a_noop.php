<?php
// Superset: no interpreter, so eval() runs nothing and answers null (php would print int(42)).
$r = eval('return 40 + 2;');
var_dump($r);
eval('class NotDeclaredByEval {}');
var_dump(class_exists('NotDeclaredByEval', false));
