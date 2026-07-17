<?php
function seq(): int {
    static $n = 0;
    $n++;
    return $n;
}
echo seq(), seq(), seq();
