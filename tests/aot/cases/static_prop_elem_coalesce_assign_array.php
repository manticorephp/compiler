<?php
// `Cls::$prop[$k] ??= [...]` over a static array of arrays yields the stored array, not the buffer's encoded word
class A {
    public static array $casters = ['x' => ['p', 'q']];
}
A::$casters['y'] ??= ['r', 's'];
A::$casters['x'] ??= ['ignored'];
$v = (A::$casters['z'] ??= ['t']);
echo json_encode(A::$casters), json_encode($v), "\n";
