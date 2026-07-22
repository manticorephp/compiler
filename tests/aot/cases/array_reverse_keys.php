<?php

// array_reverse: STRING keys are always kept; INT keys re-index unless the
// $preserve_keys flag is set. (The old body used array_values and dropped
// every key, including string ones.)
var_dump(array_reverse([1, 2, 3]));
var_dump(array_reverse([1, 2, 3], true));
var_dump(array_reverse(["a" => 1, "b" => 2, "c" => 3]));
var_dump(array_reverse(["a" => 1, "b" => 2], true));
var_dump(array_reverse(["x" => 1, 5 => 2, "y" => 3]));
var_dump(array_reverse(["x" => 1, 5 => 2, "y" => 3], true));
var_dump(array_reverse([]));
var_dump(array_reverse(["only" => "one"]));
