<?php
// A by-ref array parameter rebound to one of its own elements with `$array = &$array[$key]` must not corrupt the caller's variable
function set(array &$array, string $path, int $value): void {
    $keys = explode('.', $path);
    foreach ($keys as $i => $key) {
        if (count($keys) === 1) { break; }
        unset($keys[$i]);
        if (!isset($array[$key]) || !is_array($array[$key])) { $array[$key] = []; }
        $array = &$array[$key];
    }
    $array[array_shift($keys)] = $value;
}
$items = ['x' => 5];
set($items, 'a.b', 7);
echo json_encode($items), "\n";
