<?php
// A key write to a foreach value over a cell-element array mutates the array's own element in place, so a growing write frees a buffer the array still holds.
// issue: #150
function make(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[$i] = ['classIndex' => $i, 'type' => 'method'];
    }
    return $out;
}

$held = [];
for ($k = 0; $k < 50; $k++) {
    foreach (array_reverse(make(4), true) as $index => $element) {
        $element['index'] = $index;
        unset($element['classIndex']);
        $element['start'] = $index;
        $element['end'] = $index;
        $element['x'] = $index;
        $held[] = ['classIndex' => $k, 'type' => 'held'];
        $held[] = ['classIndex' => $k, 'type' => 'held'];
    }
    $later = [];
    for ($j = 0; $j < 16; $j++) {
        $later[] = ['classIndex' => -1, 'type' => 'later'];
    }
}
$bad = 0;
foreach ($held as $h) {
    if ('held' !== $h['type']) {
        ++$bad;
    }
}
echo count($held), ' bad=', $bad, "\n";
