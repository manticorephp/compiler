<?php
/** @return int[] */
function ints(int $n): array { $a = []; for ($i = 0; $i < $n; $i++) { $a[] = $i * 3; } return $a; }
/** @return string[] */
function strs(int $n): array { $a = []; for ($i = 0; $i < $n; $i++) { $a[] = "k" . ($i * 3); } return $a; }
/** @return float[] */
function flts(): array { return [1.5, 0.0, -0.0, 2.25, NAN]; }
/** @return bool[] */
function bools(): array { return [false, true]; }
/** @return int[] */
function noInts(): array { return []; }
/** @return string[] */
function noStrs(): array { return []; }
function show(mixed $v): void { echo var_export($v, true), "\n"; }

foreach ([0, 1, 7, 8, 9, 16, 17, 100] as $n) {
    $a = ints($n);
    $s = strs($n);
    show(in_array(0, $a)); show(array_search(0, $a));
    show(in_array(3 * ($n - 1), $a)); show(array_search(3 * ($n - 1), $a));
    show(in_array(-1, $a)); show(array_search(-1, $a));
    show(in_array("k0", $s)); show(array_search("k0", $s));
    show(in_array("k" . (3 * ($n - 1)), $s, true)); show(array_search("k" . (3 * ($n - 1)), $s, true));
    show(in_array("zz", $s)); show(array_search("zz", $s));
    show(array_search("", $s)); show(array_search("", $s, true));
}
show(array_search(1, noInts())); show(array_search("a", noStrs())); show(in_array("a", noStrs()));
// loose string compare with numeric strings must stay numeric
$ns = ["10", "1e1", " 5", "abc", "0"];
show(array_search("1e1", $ns)); show(array_search("10", $ns)); show(array_search("5", $ns));
show(array_search("1e1", $ns, true)); show(array_search("0.0", $ns)); show(in_array("1e1", $ns));
show(array_search("abc", $ns)); show(array_search("ABC", $ns));
// floats / bools
$f = flts();
show(array_search(2.25, $f)); show(array_search(0.0, $f)); show(array_search(-0.0, $f));
show(array_search(NAN, $f)); show(in_array(NAN, $f)); show(array_search(9.5, $f));
$b = bools();
show(array_search(true, $b)); show(array_search(false, $b)); show(in_array(true, $b, true));
// assoc haystacks: key is returned
$as = ["x" => "p", "y" => "q", 5 => "r", "z" => "q"];
show(array_search("q", $as)); show(array_search("r", $as, true)); show(array_search("nope", $as)); show(in_array("q", $as));
$ai = ["a" => 10, "b" => 20, 7 => 30];
show(array_search(30, $ai)); show(array_search(20, $ai, true)); show(array_search(99, $ai));
// after unset (tombstone) positions must map to live keys
$t = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
unset($t[2]); unset($t[3]);
show(array_search(40, $t)); show(array_search(50, $t)); show(array_search(100, $t));
// inside closures and methods
$c = function (array $h, string $n) { return array_search($n, $h); };
show($c(["a", "b", "c"], "c")); show($c(["a", "b", "c"], "d"));
$c2 = function (int $n) { $a = ints(50); return [array_search($n, $a), in_array($n, $a)]; };
show($c2(147)); show($c2(148));
$k = "needle";
$arr = ["hay", "needle", "stack"];
$g = fn() => array_search($k, $arr);
show($g());
// mixed-type haystacks keep generic semantics
$m = [0, "a", null, 1.0, true, "1"];
show(array_search("a", $m)); show(array_search(null, $m)); show(array_search(1, $m)); show(array_search("1", $m, true));
show(array_search(0, $m)); show(in_array("abc", $m)); show(in_array("1", $m, true));
