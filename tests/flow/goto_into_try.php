<?php
// ownflow-args: h
function h(int $n): int {
    goto L;
    try { L: $x = $n; echo $x; } finally { echo 1; }
    echo $x;
    return $n;
}
echo h(1), "\n";
