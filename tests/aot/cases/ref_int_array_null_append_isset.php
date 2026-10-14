<?php
// Appending null to a raw int array through a reference stores raw 0, so isset() of that slot is true (php: false).
$gi = [1, 2];
$r = &$gi;
$r[] = null;
var_dump(isset($gi[2]), isset($r[2]), $r[2] ?? 'm');
