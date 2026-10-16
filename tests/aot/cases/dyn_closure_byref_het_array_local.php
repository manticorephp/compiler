<?php
// A by-reference closure parameter bound through a dynamic call to a het-array local (vec[cell]).
function mk(): \Closure { return static function ($v, &$objects) { $objects[] = $v; if (count($objects) > 3) { $objects = ['reset']; } }; }
function run(\Closure $h): array {
    $objects = [1, 'a', [2]];
    $h('x', $objects);
    $h(['y'], $objects);
    return $objects;
}
echo json_encode(run(mk())), "\n";
$list = ['k' => 1, 'z' => 'a'];
$g = mk();
$g('q', $list);
echo json_encode($list), "\n";
