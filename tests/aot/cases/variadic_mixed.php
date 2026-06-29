<?php
function header(string $tag, int ...$ns): string {
    $t = 0; foreach ($ns as $n) { $t = $t + $n; }
    return $tag . "=" . $t;
}
echo header("total", 10, 20, 30);
