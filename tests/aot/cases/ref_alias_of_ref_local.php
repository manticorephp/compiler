<?php
// `$w = &$v` where `$v` is itself bound by reference (a by-ref param, a by-ref foreach variable) overwrites the binding instead of writing through it.
function loop(): void { $arr = [1, 2]; foreach ($arr as &$v) { $w = &$v; $w = 'x'; } unset($v); var_dump($arr[0], $arr[1]); }
loop();
function param(&$p): void { $w = &$p; $w = 'six'; }
$s = 1; param($s); var_dump($s);
