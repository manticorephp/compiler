<?php
// get_defined_vars() is undefined: it traps with "Call to undefined function" instead of returning the local scope.
// issue: #264
function dv(int $p) { $a = 1; $b = 'x'; unset($a); $c = [1]; return get_defined_vars(); }
echo json_encode(dv(7)), "\n";
