<?php
function g(mixed ...$args) { return json_encode($args); }
class K { public function m(mixed ...$args) { return json_encode($args); } public static function s(mixed ...$args) { return json_encode($args); } public function __construct(mixed ...$args) { echo json_encode($args), "\n"; } }
echo g(1, 2), g(b: 'bb'), g(1, x: 2), "\n";
$k = new K(message: 'm', context: []);
echo $k->m(message: 'm', ctx: [1]), K::s(a: 1), "\n";
class C2 { public function __construct(public string $a = 'A', public string $b = 'B', public string $c = 'C') {}
  public function m(string $x = 'X', string $y = 'Y') { return "$x$y"; }
  public static function s(string $x = 'X', string $y = 'Y') { return "$x$y"; } }
function f(string $x = 'X', string $y = 'Y', string $z = 'Z') { return "$x$y$z"; }
$args = ['c' => '3', 'a' => '1'];
$o = new C2(...$args); echo $o->a, $o->b, $o->c, "\n";
echo f(...['z' => 'z', 'x' => 'x']), "\n";
echo (new C2)->m(...['y' => 'y']), C2::s(...['y' => 'q']), "\n";
$a2 = ['b' => 'b']; $a2['a'] ??= 'a'; $o = new C2(...$a2); echo $o->a, $o->b, $o->c, "\n";
function g2(mixed ...$args) { $args['c'] ??= 'c'; return new C2(...$args); }
$o = g2(b: 'bb'); echo $o->a, $o->b, $o->c, "\n";
