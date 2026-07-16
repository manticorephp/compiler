<?php
function lt5(int|float $x): bool { return $x < 5; }
function gt5(int|float $x): bool { return $x > 5; }
function le5(int|float $x): bool { return $x <= 5; }
function ge5(int|float $x): bool { return $x >= 5; }
var_dump(lt5(2.5), lt5(7.5), lt5(3), lt5(9));
var_dump(gt5(2.5), gt5(7.5), gt5(3), gt5(9));
var_dump(le5(5.0), le5(5), le5(4.9));
var_dump(ge5(5.0), ge5(5), ge5(5.1));
// cell vs float
function ltf(int|float $x): bool { return $x < 5.5; }
var_dump(ltf(5.0), ltf(6.0), ltf(5));
// cell vs cell (was already ok)
function c2(int|float $a, int|float $b): bool { return $a < $b; }
var_dump(c2(2.5, 3.5), c2(4, 2.5));
// int-only still raw/fast path
function ii(int $a, int $b): bool { return $a < $b; }
var_dump(ii(1,2), ii(5,3));
