<?php
/** @param array{0:float,1:float} $q */
function scale(array $q): float { $q[0] = 3; return $q[0] * 2; }

/** @var array{0:float,1:float} $q */
$q = [1.5, 2.5];
$q[0] = 3;
var_dump($q[0]);
echo scale([1.5, 2.5]), "\n";
$f = [1.5, 2.5];
$f[0] = 3;
var_dump($f[0]);
