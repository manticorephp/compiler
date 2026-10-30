<?php
// array_unique() over the result of array_map() with a closure segfaults.
$m = array_map(fn($z) => 1, [1, 2]);
var_dump(array_unique($m));
