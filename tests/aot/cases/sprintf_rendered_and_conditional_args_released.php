<?php
function grow(string $l, callable $f) { for ($i = 0; $i < 300; $i++) { $f(); } $a = memory_get_peak_usage(); for ($i = 0; $i < 60000; $i++) { $f(); } $d = memory_get_peak_usage() - $a; printf("%-10s %s\n", $l, $d < 1000000 ? "flat" : "GREW " . (int)($d / 60000) . " B/iter"); }
function f1() { $x = sprintf('%s', 5); }
function f2() { $x = sprintf('%s:%s', 'a', 'b'); }
function f3() { $x = sprintf('%s:%d', 'a', 5); }
function f4() { $x = vsprintf('%s:%s', ['a', 5]); }
function f5() { $f = '%s:%s'; $x = sprintf($f, 'a', 'b'); }
function f6() { $f = '%s:%s'; $x = sprintf($f, 'a', 5); }
function f7() { $x = sprintf('%s', 2.5); }
function f8() { $x = sprintf('%s', true); }
function f9() { $x = (string)5; }
function f10() { $x = strval(5); }
foreach (['f1','f2','f3','f4','f5','f6','f7','f8','f9','f10'] as $t) { grow($t, fn () => $t()); }
function f11(): mixed { return 7; }
function f12(): mixed { return "str"; }
function f13() { $x = sprintf('%s', f11()); }
function f14() { $x = sprintf('[%5s]', f12()); }
function f15() { $x = sprintf('%s', str_repeat("q", 50) . f12()); }
foreach (['f13','f14','f15'] as $t) { grow($t, fn () => $t()); }
var_dump(sprintf('%s|%s|%s|%s|%s', 5, 2.5, true, null, 'x'), sprintf('%s', f11()), sprintf('[%5s]', f12()), sprintf('%05.1f %s', 3.14159, f11()), vsprintf('%2$s %1$s', [1, f12()]));
function nm() { return str_repeat("Illuminate", 8); }
function g1() { $c = nm(); $x = sprintf('%s@%s', 'a', $c ? $c . '@' : ''); }
function g2() { $c = f12(); $x = sprintf('%s:%s', $c ? $c . '@' : '', 5); }
foreach (['g1','g2'] as $t) { grow($t, fn () => $t()); }
