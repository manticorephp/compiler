<?php
function io(array $a): bool {
    $present = isset($a["k"]);
    unset($a["k"]);
    return $present;
}
echo io(["k" => 1]) ? "y" : "n";
