<?php
// array_unique over an array with both string and int keys prints the string keys as addresses
// issue: #310
echo json_encode(array_unique(["x" => "p", "y" => "q", "z" => "p", 5 => "q"])), "\n";
