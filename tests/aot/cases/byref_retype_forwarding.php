<?php
// A typed by-ref param retypes when its word is rewritten through a hand-off
// (forwarding to a by-ref param of another representation), a use(&) closure,
// or a closure / dynamic-name call site.
function g(int &$k): void { $k = 's'; }
function h(int &$i): void { g($i); }
$c = 1; h($c); var_dump($c);
function g2(&$v): void { $v = 'g'; }
function h2(int &$i): void { g2($i); }
$c2 = 1; h2($c2); var_dump($c2);
function cu(int &$i): void { $c = function () use (&$i) { $i = 'c'; }; $c(); }
$c3 = 1; cu($c3); var_dump($c3);
$cl = function (int &$i): void { $i = strpos('abc', 'z'); };
$x = 5; $cl($x); var_dump($x);
final class UT { public function t(int &$i): void { $i = 1.5; } }
$m = 't'; $q2 = 0; (new UT)->$m($q2); var_dump($q2);
// symfony/yaml shape: int &$i forwarded down a chain to the body that stores false.
final class Y {
    public static function seq(string $s, int &$i = 0): string { return self::scalar($s, $i); }
    private static function scalar(string $s, int &$i): string { return self::map($s, $i); }
    private static function map(string $s, int &$i): string {
        $out = '';
        while ($i < \strlen($s)) {
            $out .= $s[$i];
            if (false === $i = strpos($s, ',', $i)) { break; }
            ++$i;
        }
        return $out;
    }
}
$yi = 0; echo Y::seq('a,b,c', $yi), "\n"; var_dump($yi);
// An unrelated method of the same name keeps its raw int and still works.
final class Other { public function t(int &$i): void { $i = $i + 1; } }
$oi = 10; (new Other)->t($oi); var_dump($oi);
// A closure forwarding its capture into a retyping callee.
function fw(int &$i): void { $f = function () use (&$i) { g($i); }; $f(); }
$fi = 3; fw($fi); var_dump($fi);
