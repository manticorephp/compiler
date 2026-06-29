<?php
function side($n) { echo "S", $n, ";"; return $n; }
$r = side(7) || side(1);
echo "/", $r;
