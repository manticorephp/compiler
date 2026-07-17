<?php
function id(): int {
    $x = 0;
    $x++;
    ++$x;
    $x--;
    return $x;
}
echo id();
