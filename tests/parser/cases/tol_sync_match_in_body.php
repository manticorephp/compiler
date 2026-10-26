<?php
function f($x){
  $y = match ($x) { 1 = 2, default => 3 };
  return $y;
}
$z = 1;
