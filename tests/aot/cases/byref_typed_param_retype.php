<?php
// A typed by-ref param is typed on ENTRY only: a body storing another kind
// leaves that kind in the caller's variable (symfony/yaml Inline::parseMapping).
final class P {
    public static function m(string $s, int &$i = 0): string {
        $len = \strlen($s); ++$i; $out = '';
        while ($i < $len) {
            var_dump($i);
            $out .= $s[$i];
            if (false === $i = strpos($s, ':', $i)) { break; }
            ++$i;
        }
        return $out;
    }
    public function inst(int &$n): void { $n = $n > 0 ? "pos" : null; }
}
function colon_at(string $s, int &$i): void { $i = strpos($s, ':', $i); }
function str(int &$i): void { $i = "x" . $i; }
function flt(int &$i): void { $i = $i + 0.5; }
function cmp(int &$i): void { $i += 1.5; }
function tostr(string &$s): void { $s = strlen($s); }
function tobool(float &$f): void { $f = $f > 1.0; }

$i = 0;
echo P::m("{a:b:c}", $i), "\n";
var_dump($i);
$j = 0; colon_at("{a:b}", $j); var_dump($j);
$j = 0; colon_at("{ab}", $j); var_dump($j);
$k = 7; str($k); var_dump($k);
$l = 1; flt($l); var_dump($l);
$m = 1; cmp($m); var_dump($m);
$s = "abcd"; tostr($s); var_dump($s);
$f = 2.5; tobool($f); var_dump($f);
$o = new P(); $n = 3; $o->inst($n); var_dump($n); $n = 0; $o->inst($n); var_dump($n);
for ($q = 0; $q < 3; $q++) { $w = $q; colon_at("a:b", $w); var_dump($w); }
abstract class Base { abstract public function step(int &$x): void; }
final class Stepper extends Base { public function step(int &$x): void { $x = $x > 5 ? false : $x + 1; } }
final class Plain extends Base { public function step(int &$x): void { $x = $x + 10; } }
/** @param Base[] $bs */
function run_all(array $bs): void {
    foreach ($bs as $b) { $v = 3; $b->step($v); var_dump($v); $v = 9; $b->step($v); var_dump($v); }
}
run_all([new Stepper(), new Plain()]);
interface I { public function st(int &$x): void; }
final class Q implements I { public function st(int &$x): void { $x = $x > 1 ? "big" : 0; } }
final class R implements I { public function st(int &$x): void { $x++; } }
/** @param I[] $is */
function run_i(array $is): void { foreach ($is as $i) { $v = 3; $i->st($v); var_dump($v); } }
run_i([new Q(), new R()]);
