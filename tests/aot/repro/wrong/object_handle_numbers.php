<?php
// var_dump prints #1 for every object and spl_object_id answers an address; php numbers object handles 1, 2, 3.
// issue: #457
$a = new stdClass();
$b = new stdClass();
echo spl_object_id($a), " ", spl_object_id($b), "\n";
var_dump($a, $b);
