<?php
$x = 100;
$out = match ($x) {
    1 => "uno",
    2 => "due",
    default => "other",
};
echo $out;
