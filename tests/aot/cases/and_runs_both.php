<?php
function side($n) { echo "S", $n, ";"; return $n; }
$r = side(3) && side(4);
echo "/", $r;
