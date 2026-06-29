<?php
function confined(int $n): int {
    $local = "tmp" . $n;
    $arr = [$n, $n + 1];
    echo $local;
    return $arr[0];
}
function escapes(int $n): string {
    $kept = "keep" . $n;
    return $kept;
}
echo escapes(1);
