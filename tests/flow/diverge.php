<?php
// ownflow-args: d diverge
function d(int $n): int {
    while ($n > 0) { $n--; }
    return $n;
}
echo d(3), "\n";
