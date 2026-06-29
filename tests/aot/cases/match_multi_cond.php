<?php
$x = 5;
$bucket = match ($x) {
    0, 1, 2 => "small",
    3, 4, 5 => "medium",
    default => "large",
};
echo $bucket;
