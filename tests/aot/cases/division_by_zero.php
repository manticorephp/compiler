<?php
// DivisionByZeroError / ArithmeticError from `/`, `%`, `intdiv`, shifts — typed,
// mixed, compound and literal operands; `% -1` and shifts of 64+ never trap.
function t(callable $f, string $l): void { try { var_dump($f()); } catch (Throwable $e) { echo $l, ": ", get_class($e), ": ", $e->getMessage(), "\n"; } }
$z = 0; $zf = 0.0; $m = -1; $mn = PHP_INT_MIN; $zs = "0"; $mx = 0;
t(fn() => 1 % $z, '%');
t(fn() => 1 / $z, '/');
t(fn() => 1.5 / $zf, '/f');
t(fn() => intdiv(1, $z), 'intdiv');
t(fn() => intdiv($mn, $m), 'intdiv min');
t(fn() => $mn % $m, '% min');
t(fn() => fmod(1, 0), 'fmod');
t(fn() => 1 / $zs, '/str');
t(function () { $a = 5; $a %= 0; return $a; }, '%=');
t(function () use ($z) { $a = 5; $a /= $z; return $a; }, '/=');
t(fn() => 7 % -3, 'neg');
t(fn() => 1 << -1, 'shift');
t(fn() => 1 >> -1, 'shiftr');
function mixedDiv(mixed $a, mixed $b): mixed { return $a / $b; }
function mixedMod(mixed $a, mixed $b): mixed { return $a % $b; }
t(fn() => mixedDiv(1, 0), 'mixed /');
t(fn() => mixedMod(1, 0), 'mixed %');
t(fn() => mixedDiv(1.0, null), 'mixed / null');
t(fn() => mixedMod(PHP_INT_MIN, -1), 'mixed % min');
t(fn() => 10 / 4, 'ok');
t(fn() => 10 % 4, 'ok%');
$s64 = 64; $s70 = 70; $h = 0.5; $sz = "0"; $n = -8;
t(fn() => 1 << $s64, 'shl64');
t(fn() => $n >> $s70, 'shr70');
t(fn() => 8 >> $s70, 'shr70p');
t(fn() => 5 % $sz, 'mod str');
t(function () { $a = 1; $b = -1; $a <<= $b; return $a; }, '<<=');
t(fn() => fdiv(1, 0), 'fdiv');
t(fn() => fdiv(-1, 0), 'fdiv-');
t(fn() => 0 / 0.0, 'zz');
t(fn() => -0.0 / 1, 'nz');
$tot = 0;
foreach ([2, 0, 4, 0] as $d) { try { $tot += intdiv(8, $d); } catch (DivisionByZeroError $e) { $tot += 100; } }
var_dump($tot);
echo intdiv(PHP_INT_MIN, 1), " ", PHP_INT_MIN % -1, " ", -7 % 3, " ", 7 % -3, "\n";
t(fn() => 1 % 0, 'lit%');
t(fn() => 1 / 0, 'lit/');
t(fn() => intdiv(1, 0), 'lit intdiv');
t(fn() => intdiv(PHP_INT_MIN, -1), 'lit intdiv min');
t(fn() => 1 << -1, 'lit shl');
t(fn() => 1 << 64, 'lit shl64');
t(fn() => fdiv(1, 0), 'fdiv');
t(fn() => fdiv(0, 0), 'fdiv0');
t(fn() => fdiv(-1, -0.0), 'fdivnz');
const ZZ = 0;
t(fn() => 10 % ZZ, 'const%');
echo 10 / 4, " ", 10 % 3, "\n";
