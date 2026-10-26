<?php
class Node {}
class IntConst extends Node { public function __construct(public int $value) {} }
class Arm { public function __construct(public ?Node $value) {} }
class Other { public string $value = 'x'; }
function f(array $arms): void
{
    $seen = [];
    foreach ($arms as $arm) {
        if ($arm->value instanceof IntConst && !isset($seen[$arm->value->value])) {
            $seen[$arm->value->value] = true;
            echo $arm->value->value, "\n";
        }
    }
}
f([new Arm(new IntConst(7)), new Arm(null), new Arm(new IntConst(9)), new Other()]);
