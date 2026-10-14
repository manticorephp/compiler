<?php
// closure called with more args than params: func_get_args() drops the extras (func_num_args is right)
$g = function ($a) { return json_encode(func_get_args()); };
echo $g(1, 2, 3), "\n";
