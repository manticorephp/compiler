<?php
function next_id(): int {
    static $n = 0;
    $n = $n + 1;
    return $n;
}
echo next_id(), ",", next_id(), ",", next_id();
