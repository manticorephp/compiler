<?php
// json_encode and get_object_vars show uninitialized typed properties, which php skips
// issue: #115
class Typed { public int $set = 1; public int $unset; public ?int $nul; }
$t = new Typed();
echo json_encode($t), "\n";
var_dump(array_keys(get_object_vars($t)));
