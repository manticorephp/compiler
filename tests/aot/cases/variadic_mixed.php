<?php
function tally(string $tag, int ...$ns): string {
    $t = 0; foreach ($ns as $n) { $t = $t + $n; }
    return $tag . "=" . $t;
}
echo tally("total", 10, 20, 30);
