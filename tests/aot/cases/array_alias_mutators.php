<?php
// PHP arrays are VALUES: `$b = $a` must survive any later in-place mutation of
// `$a`. The copy-on-assign decision is driven by a pre-scan that knew only the
// four cursor moves, so every other by-ref builtin (array_pop / array_push /
// sort / usort / …) mutated the alias too — and a REALLOCATING one
// (array_unshift, array_push) left the alias reading a freed buffer.
function show(string $tag, array $src, array $alias): void
{
    echo $tag, " src=", implode(",", $src), " alias=", implode(",", $alias), "\n";
}

$a1 = [3, 1, 2]; $b1 = $a1; array_pop($a1);                      show("pop     ", $a1, $b1);
$a2 = [3, 1, 2]; $b2 = $a2; array_shift($a2);                    show("shift   ", $a2, $b2);
$a3 = [3, 1, 2]; $b3 = $a3; array_unshift($a3, 9);               show("unshift ", $a3, $b3);
$a4 = [3, 1, 2]; $b4 = $a4; array_push($a4, 9);                  show("push    ", $a4, $b4);
$a5 = [3, 1, 2]; $b5 = $a5; sort($a5);                           show("sort    ", $a5, $b5);
$a6 = [3, 1, 2]; $b6 = $a6; rsort($a6);                          show("rsort   ", $a6, $b6);
$a7 = [3, 1, 2]; $b7 = $a7; array_splice($a7, 0, 1);             show("splice  ", $a7, $b7);
$a8 = [3, 1, 2]; $b8 = $a8; usort($a8, fn($x, $y) => $x <=> $y); show("usort   ", $a8, $b8);
$a9 = [3, 1, 2]; $b9 = $a9; shuffle($a9);                        echo "shuffle  alias=", implode(",", $b9), "\n";

// string elements: the copy must own its own element rc
$s1 = ["b", "c"]; $s2 = $s1; array_unshift($s1, "a");
show("str     ", $s1, $s2);

// assoc order mutators
$k1 = ["b" => 2, "a" => 1]; $k2 = $k1; ksort($k1);
echo "ksort    src=", implode(",", array_keys($k1)), " alias=", implode(",", array_keys($k2)), "\n";
$k3 = ["b" => 2, "a" => 1]; $k4 = $k3; asort($k3);
echo "asort    src=", implode(",", array_keys($k3)), " alias=", implode(",", array_keys($k4)), "\n";

// the alias is the one mutated — the copy must happen either way round
$m1 = [1, 2]; $m2 = $m1; array_unshift($m2, 0);
show("aliasmut", $m1, $m2);

// cursor moves (the shapes the pre-scan already knew) still behave
$c1 = [1, 2, 3]; $c2 = $c1; next($c1);
echo "next     src=", current($c1), " alias=", current($c2), "\n";

// three-way: two aliases of one source
$t1 = [1, 2]; $t2 = $t1; $t3 = $t1; array_pop($t1);
echo "three    ", implode(",", $t1), " | ", implode(",", $t2), " | ", implode(",", $t3), "\n";

// ⚠ NOT covered here: a NESTED mutation (`array_pop($n1[0])` after `$n2 = $n1`).
// The copy-on-assign is SHALLOW, so the alias keeps pointing at the same inner
// buffers — an open gap that predates this file and shows identically for
// `$n1[0][] = 9` and `$n1[0][0] = 99`, i.e. it belongs to the copy, not to the
// mutator list. Adding a row for it here would only pin the wrong answer.
