<?php
// The value of an untyped function that never returns anything is int(0) where php yields NULL
// issue: #494
function f($x) {
    if ($x) {
        echo "a\n";
    }
}
var_dump(f(true));
echo json_encode(f(false)), "\n";
