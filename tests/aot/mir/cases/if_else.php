<?php
function sign(int $n): int {
    if ($n > 0) {
        return 1;
    } else {
        return -1;
    }
}
echo sign(5);
