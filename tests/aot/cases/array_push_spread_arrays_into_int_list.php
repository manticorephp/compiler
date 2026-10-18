<?php
function build(): array { $l = []; $l[10][] = ['a', 1]; $l[5][] = ['b', 2]; return $l; }
$l = build();
$z = [0];
array_push($z, ...$l);
echo json_encode($z), json_encode($l), "\n";
$y = [0]; array_push($y, ...[[1],[2]]); echo json_encode($y),"\n";
$w = [0]; array_push($w, ...[1,2]); echo json_encode($w),"\n";
function mk(): array { $l = []; $l[7][] = 1.5; $l[8][] = 2.5; return $l; }
$m = mk(); $s = ['q']; array_push($s, ...$m); echo json_encode($s), json_encode($m), "\n";
$m = mk(); $u = [0]; array_unshift($u, ...$m); echo json_encode($u), json_encode($m), "\n";
function ms(): array { $l = []; $l[3][] = 's'; $l[1][] = 't'; return $l; }
$m = ms(); $f = [1.5]; array_push($f, ...$m); echo json_encode($f), json_encode($m), "\n";
