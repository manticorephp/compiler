<?php

// Math builtins on a numeric CELL arg (float|false / int|false / ?float / ?int)
// must decode by tag, not read the NaN-boxed carrier as a raw int/double.

function ff(bool $c): float|false { return $c ? -5.5 : false; }
function pf(bool $c): float|false { return $c ? 3.7 : false; }
function nf(bool $c): ?float { return $c ? 2.25 : null; }
function iff(bool $c): int|false { return $c ? -9 : false; }
function ni(bool $c): ?int { return $c ? -4 : null; }

var_dump(abs(ff(true)));     // float(5.5)
var_dump(abs(pf(true)));     // float(3.7)
var_dump(abs(iff(true)));    // int(9)
var_dump(abs(ni(true)));     // int(4)
var_dump(floor(pf(true)));   // float(3)
var_dump(ceil(pf(true)));    // float(4)
var_dump(round(pf(true)));   // float(4)
var_dump(round(nf(true), 1));// float(2.3) -> php round(2.25,1)=2.3? actually 2.2 or 2.3
var_dump(sqrt(nf(true)));    // float(1.5)
var_dump(intval(pf(true)));  // int(3)
var_dump(max(ff(true), 0.0));// float(0)
var_dump(min(pf(true), 1.0));// float(1)
var_dump(fmod(pf(true), 2.0));// float(1.7)
var_dump(hypot(pf(true), 4.0));

// real float|false source
$d = disk_free_space("/");
var_dump(floor($d / 1e9) >= 0);
var_dump(sqrt($d) > 0);
