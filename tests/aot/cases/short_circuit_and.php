<?php
function side($n) { echo "S", $n, ";"; return $n; }
// Right side must NOT run when left is falsy.
$r = side(0) && side(1);
echo "/", $r;
