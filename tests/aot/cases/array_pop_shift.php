<?php
$nums = [10, 20, 30];
echo array_pop($nums), ",";
echo count($nums), ",";
echo array_shift($nums), ",";
echo count($nums), ",";
echo $nums[0], "\n";

$words = ["a", "b", "c"];
echo array_pop($words), ",", array_shift($words), ",", $words[0];
