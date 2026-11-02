<?php
// A function returning a nested array built in a foreach keeps a stale `unknown` inner element in its return type; the caller crashes or prints empty arrays.
function leaf(string $n): array { return ['name' => '--' . $n, 'd' => strlen($n) > 1 ? $n : null]; }
function def(array $names): array {
    $out = [];
    foreach ($names as $n) { $out[$n] = leaf($n); }
    return ['arguments' => $out];
}
function cmd(array $names): array {
    $data = ['name' => 'x'];
    $data += ['usage' => ['a'], 'definition' => def($names)];
    $data['hidden'] = false;
    return $data;
}
echo json_encode(cmd(['ab', 'c'])), "\n";
