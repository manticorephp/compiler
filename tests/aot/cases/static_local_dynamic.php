<?php
function seed(): int { return 100; }
function next_id(): int {
    static $n = seed();
    $n = $n + 1;
    return $n;
}
echo next_id(), ",", next_id(), ",", next_id();
