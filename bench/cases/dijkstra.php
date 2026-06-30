<?php
// Dijkstra shortest paths on a generated dense graph — 2D adjacency,
// arrays as the visited/dist sets, integer output (sum of all distances).

function buildGraph(int $n): array {
    $g = [];
    $v = 12345;
    for ($i = 0; $i < $n; $i++) {
        $row = [];
        for ($j = 0; $j < $n; $j++) {
            if ($i === $j) { $row[$j] = 0; continue; }
            $v = ($v * 1103515245 + 12345) % 100;
            $row[$j] = $v + 1;
        }
        $g[$i] = $row;
    }
    return $g;
}

function dijkstra(array $g, int $n, int $src): array {
    $dist = [];
    $done = [];
    for ($i = 0; $i < $n; $i++) { $dist[$i] = PHP_INT_MAX; $done[$i] = false; }
    $dist[$src] = 0;
    for ($k = 0; $k < $n; $k++) {
        $u = -1; $best = PHP_INT_MAX;
        for ($i = 0; $i < $n; $i++) {
            if (!$done[$i] && $dist[$i] < $best) { $best = $dist[$i]; $u = $i; }
        }
        if ($u === -1) { break; }
        $done[$u] = true;
        for ($w = 0; $w < $n; $w++) {
            $nd = $dist[$u] + $g[$u][$w];
            if ($nd < $dist[$w]) { $dist[$w] = $nd; }
        }
    }
    return $dist;
}

$n = 150;
$g = buildGraph($n);
$total = 0;
for ($s = 0; $s < $n; $s++) {
    $d = dijkstra($g, $n, $s);
    for ($i = 0; $i < $n; $i++) { $total += $d[$i]; }
}
echo "total=", $total, "\n";
