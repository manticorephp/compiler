<?php
class C {
  public function __construct(int ...$m) { echo "ctor ", array_sum($m), "\n"; }
  public function m(string $p, string ...$m): string { return $p . implode(",", $m); }
  public static function s(int $x, int ...$m): int { return $x + array_sum($m); }
}
function f(int $x, int ...$m): int { return $x + array_sum($m); }
function g(mixed $a): void {
  echo f($a[0], $a[1], $a[2]), "\n";
  new C($a[0], $a[1]);
  echo (new C())->m("p", $a[3], $a[4]), "\n";
  echo C::s($a[0], $a[1], $a[2]), "\n";
  $c = fn(int ...$z) => array_sum($z);
  echo $c($a[0], $a[1]), "\n";
  $n = 'f'; echo $n($a[0], $a[1], $a[2]), "\n";
  echo call_user_func('f', $a[0], $a[1], $a[2]), "\n";
  $fc = f(...); echo $fc($a[0], $a[1]), "\n";
  $mm = 'm'; echo (new C())->$mm("q", $a[3]), "\n";
}
g([1, 2, 3, "x", "y"]);
